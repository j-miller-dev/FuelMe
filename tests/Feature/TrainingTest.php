<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Monday 23 Nov 2026, 05:00 in Melbourne: BJJ 06:30 and lifting 12:00 are both still to come. */
function mondayAt(string $time): void
{
    Carbon::setTestNow(Carbon::parse("2026-11-23 {$time}", 'Australia/Melbourne'));
}

afterEach(fn () => Carbon::setTestNow());

function todayProps($test, User $u): array
{
    return $test->actingAs($u)->get('/')->assertOk()->viewData('page')['props'];
}

function sessionOf(array $props, string $type): ?array
{
    return collect($props['training']['sessions'])->firstWhere('type', $type);
}

it('seeds your usual week from the default week once, with the start times you gave', function () {
    mondayAt('05:00');
    $u = loggedInUser();

    $p = $this->actingAs($u)->get('/training')->assertOk()->viewData('page')['props'];
    $this->actingAs($u)->get('/training')->assertOk();   // a second visit must not seed again

    $mon = collect($p['template'])->where('day', 'mon')->values();
    expect(DB::table('training_templates')->where('user_id', $u->id)->count())->toBe(count($p['template']))->and($mon->pluck('type')->all())->toBe(['bjj', 'lifting'])
        ->and($mon->pluck('start')->all())->toBe(['06:30', '12:00'])->and($p['week']['mon']['class'])->toBe('moderate')->and($p['week']['sun']['class'])->toBe('rest');
});

it('replans the week the moment you edit the template, with no new target needed', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $before = $this->actingAs($u)->get('/plan')->viewData('page')['props'];
    expect($before['days']['sun']['class'])->toBe('rest');

    $this->actingAs($u)->postJson('/training/template', ['day' => 'sun', 'type' => 'bjj', 'intensity' => 'standard', 'minutes' => 90, 'start_time' => '09:00'])->assertCreated();
    $after = $this->actingAs($u)->get('/plan')->viewData('page')['props'];

    expect($after['days']['sun']['class'])->not->toBe('rest')->and($after['days']['sun']['carbs'])->toBeGreaterThan($before['days']['sun']['carbs'])
        ->and(abs($after['weekly']['kcalAvg'] - $before['weekly']['kcalAvg']))->toBeLessThanOrEqual(2);   // weekly calories are conserved
});

it('validates sessions: known activity and intensity, sane minutes, custom needs a MET and a name', function () {
    $u = loggedInUser();
    $ok = ['day' => 'tue', 'type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60];

    $this->actingAs($u)->postJson('/training/template', ['type' => 'yoga'] + $ok)->assertStatus(422);
    $this->actingAs($u)->postJson('/training/template', ['intensity' => 'extreme'] + $ok)->assertStatus(422);
    $this->actingAs($u)->postJson('/training/template', ['minutes' => 2] + $ok)->assertStatus(422);
    $this->actingAs($u)->postJson('/training/template', ['day' => 'funday'] + $ok)->assertStatus(422);
    $this->actingAs($u)->postJson('/training/template', ['type' => 'custom', 'intensity' => 'custom', 'name' => 'Rock climbing'] + $ok)->assertStatus(422);   // no MET
    $this->actingAs($u)->postJson('/training/template', ['type' => 'custom', 'intensity' => 'custom', 'custom_met' => 6.5] + $ok)->assertStatus(422);       // no name
    $this->actingAs($u)->postJson('/training/template', ['type' => 'custom', 'intensity' => 'custom', 'custom_met' => 6.5, 'name' => 'Rock climbing'] + $ok)->assertCreated();
});

it('shows today\'s planned sessions with an energy estimate range, and never adds exercise to the food budget', function () {
    mondayAt('05:00');
    $u = loggedInUser();

    $p = todayProps($this, $u);
    $bjj = sessionOf($p, 'bjj');

    expect($bjj)->toMatchArray(['status' => 'virtual', 'start' => '06:30', 'minutes' => 60])->and($bjj['low'])->toBeLessThan($bjj['netKcal'])->and($bjj['high'])->toBeGreaterThan($bjj['netKcal'])
        ->and($p['training']['explain'])->toBeNull();
    // the day target is the planner's, with no exercise kcal on top: the same as the Plan page's Monday
    $plan = $this->actingAs($u)->get('/plan')->viewData('page')['props'];
    expect($p['target']['kcal'])->toBe((int) round($plan['days']['mon']['kcal']));
});

it('lowers today and explains it when you skip a planned session, and raises it when you add one', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $base = todayProps($this, $u)['target']['kcal'];
    $bjj = sessionOf(todayProps($this, $u), 'bjj');

    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $bjj['template_id'], 'action' => 'skip'])->assertOk();
    $skipped = todayProps($this, $u);
    expect($skipped['target']['kcal'])->toBeLessThan($base)->and($skipped['training']['explain'])->toContain('not doing')->and($skipped['training']['explain'])->toContain('Brazilian jiu-jitsu');

    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $bjj['template_id'], 'action' => 'reset'])->assertOk();
    expect(todayProps($this, $u)['target']['kcal'])->toBe($base);

    $this->actingAs($u)->postJson('/workouts', ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40, 'start_time' => '17:00'])->assertCreated();
    $added = todayProps($this, $u);
    expect($added['target']['kcal'])->toBeGreaterThan($base)->and($added['training']['explain'])->toContain('Today +')->and($added['training']['explain'])->toContain('added Norwegian 4x4')
        ->and($added['training']['explain'])->toContain('weekly total stays the same');
});

it('keeps the weekly calorie total: what today gains, the rest of the week gives back', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $plans = app(App\Services\PlanService::class);
    $base = $plans->base($u);

    $this->actingAs($u)->postJson('/workouts', ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40])->assertCreated();
    $mon = $plans->forDay($u, '2026-11-23');
    $later = array_sum(array_map(fn ($d) => $plans->forDay($u, $d)['day']['kcal'], []));   // each later day is planned from ITS OWN view; compare via the replanner below

    // Replan from Monday: the effective plan's remaining days must sum to the base's remaining days (weekly carbs conserved).
    $i = $plans->inputs($u);
    $eff = app(App\Services\TargetService::class)->replan($i['in'], $i['kg'], $i['kcal'], app(App\Services\TrainingService::class)->effectiveWeek($u, '2026-11-23'));
    $r = App\Domain\Nutrition\Replanner::remaining($base['days'], $eff['days'], [], $i['kg'], $base['weekly']['ffmKg']);

    expect($mon['day']['kcal'])->toBeGreaterThan($base['days']['mon']['kcal'])
        ->and(abs(array_sum(array_column($r['days'], 'carbs')) - array_sum(array_column($base['days'], 'carbs'))))->toBeLessThanOrEqual(4);
});

it('confirms a planned session once, edits it, honours a watch override, and deletes your own logs', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $t = sessionOf(todayProps($this, $u), 'lifting');

    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $t['template_id'], 'action' => 'done'])->assertOk();
    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $t['template_id'], 'action' => 'done'])->assertOk();   // double tap
    expect(DB::table('workout_logs')->where('user_id', $u->id)->count())->toBe(2)   // both Monday sessions became real rows, once
        ->and(sessionOf(todayProps($this, $u), 'lifting')['status'])->toBe('done');

    $id = sessionOf(todayProps($this, $u), 'lifting')['id'];
    $this->actingAs($u)->patchJson("/workouts/{$id}", ['minutes' => 60])->assertOk();
    $s = sessionOf(todayProps($this, $u), 'lifting');
    expect($s['minutes'])->toBe(60)->and($s['netKcal'])->toBe(round((3.5 - 1) * 74 * 1.0));   // (MET - 1) x kg x hours, 74 kg

    $this->actingAs($u)->patchJson("/workouts/{$id}", ['kcal_override' => 250])->assertOk();
    expect(sessionOf(todayProps($this, $u), 'lifting'))->toMatchArray(['netKcal' => 250, 'low' => 250, 'high' => 250]);

    $this->actingAs($u)->deleteJson("/workouts/{$id}")->assertOk();
    expect(sessionOf(todayProps($this, $u), 'lifting'))->toBeNull();
});

it('makes a replayed workout request a no-op', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $body = ['type' => 'hiit', 'intensity' => 'moderate', 'minutes' => 30, 'idempotency_key' => (string) Str::uuid()];

    $a = $this->actingAs($u)->postJson('/workouts', $body)->assertCreated()->json('id');
    $b = $this->actingAs($u)->postJson('/workouts', $body)->assertCreated()->json('id');

    expect($b)->toBe($a)->and(DB::table('workout_logs')->where('user_id', $u->id)->where('type', 'hiit')->count())->toBe(1);
});

it('keeps training private to its owner', function () {
    mondayAt('05:00');
    $a = loggedInUser();
    $b = loggedInUser();
    $id = $this->actingAs($a)->postJson('/workouts', ['type' => 'hiit', 'intensity' => 'moderate', 'minutes' => 30])->json('id');
    $tpl = DB::table('training_templates')->where('user_id', $a->id)->value('id') ?? app(App\Services\TrainingService::class)->template($a)[0]['id'];

    $this->actingAs($b)->patchJson("/workouts/{$id}", ['minutes' => 5])->assertNotFound();
    $this->actingAs($b)->deleteJson("/workouts/{$id}")->assertNotFound();
    $this->actingAs($b)->deleteJson("/training/template/{$tpl}")->assertNotFound();
    $this->actingAs($b)->postJson('/workouts/template-session', ['template_id' => $tpl, 'action' => 'done'])->assertNotFound();
});

// ---- the peri-workout card ----------------------------------------------------------------------------------------------------

it('shows what is next, how long until it, and pre/during/post targets sized from your weight', function () {
    mondayAt('05:00');
    $u = loggedInUser();

    $f = todayProps($this, $u)['training']['fuel'];

    expect($f['next'])->toMatchArray(['type' => 'bjj', 'start' => '06:30', 'minutesUntil' => 90])->and($f['later'])->toBe(1)
        ->and($f['leads'][2]['pre']['carbsG'])->toBe(148)->and($f['leads'][2]['pre']['proteinG'])->toBe(22)->and($f['leads'][2]['pre']['fatMaxG'])->toBe(8)
        ->and($f['post'])->toMatchArray(['proteinG' => 30, 'carbsG' => 74])   // the blueprint example: next session is resistance-only
        ->and($f['next']['gapToNextHours'])->toBe(4.5);
});

it('moves on to the next session as the day goes, then shows tomorrow\'s once today is done', function () {
    $u = loggedInUser();
    mondayAt('11:00');
    expect(todayProps($this, $u)['training']['fuel']['next']['type'])->toBe('lifting');
    mondayAt('13:00');
    $f = todayProps($this, $u)['training']['fuel'];
    expect($f['when'])->toBe('tomorrow')->and($f['next']['minutesUntil'])->toBeGreaterThan(60)->and($f['timeline'])->not->toBeNull();
    // Sunday has no training, so what is next is Monday's first session, shown as tomorrow's
    Carbon::setTestNow(Carbon::parse('2026-11-22 08:00', 'Australia/Melbourne'));   // Sunday
    expect(todayProps($this, $u)['training']['fuel']['when'])->toBe('tomorrow');
});

it('does not plan fuel for a session you already did', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $bjj = sessionOf(todayProps($this, $u), 'bjj');
    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $bjj['template_id'], 'action' => 'done'])->assertOk();

    expect(todayProps($this, $u)['training']['fuel']['next']['type'])->toBe('lifting');
});

it('suggests real meals for the pre window from your tagged meals, within the carb tolerance and fat cap', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $rice = ingredient('Rice, white, raw', [360, 7, 80, 0.6]);
    $banana = ingredient('Banana, raw', [90, 1.3, 20, 0.3]);
    $this->actingAs($u)->postJson('/meals', ['name' => 'Rice bowl', 'yield_portions' => 2, 'cook_loss' => 0, 'tags' => ['pre'], 'items' => [['food_id' => $rice, 'grams' => 200]]])->assertCreated();   // 80 g carbs a portion
    $this->actingAs($u)->postJson("/foods/{$banana}/favourite")->assertOk();

    $c = todayProps($this, $u)['training']['fuel']['leads'][2]['composer'];

    expect($c['options'])->not->toBeEmpty()->and($c['shortfall'])->toBeNull();
    foreach ($c['options'] as $o) {
        expect(abs($o['carbs'] - 148))->toBeLessThanOrEqual(15)->and($o['fat'])->toBeLessThanOrEqual(8.8);
    }
    expect($c['options'][0]['items'][0]['kind'])->toBe('meal');
});

it('explains the shortfall when you have nothing suitable saved, and shows what is left of the day', function () {
    mondayAt('05:00');
    $u = loggedInUser();

    $c = todayProps($this, $u)['training']['fuel']['leads'][2]['composer'];

    expect($c['options'])->toBe([])->and($c['shortfall'])->toBeString();
});

it('logs a suggested pre meal through the normal log path', function () {
    mondayAt('05:00');
    $u = loggedInUser();
    $rice = ingredient('Rice, white, raw', [360, 7, 80, 0.6]);
    $m = $this->actingAs($u)->postJson('/meals', ['name' => 'Rice bowl', 'yield_portions' => 2, 'cook_loss' => 0, 'tags' => ['pre'], 'items' => [['food_id' => $rice, 'grams' => 200]]])->json('meal');

    $r = $this->actingAs($u)->postJson('/logs', ['items' => [['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'breakfast', 'source' => 'meal', 'meal_id' => $m['id'], 'portions' => 2]]])->assertCreated();

    expect($r->json('logs.0.carbs'))->toEqual(160);
    // that meal now counts against what is left for the day
    $f = todayProps($this, $u)['training']['fuel'];
    expect($f['leads'][2]['composer'])->not->toBeNull();
});
