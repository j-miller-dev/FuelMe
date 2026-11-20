<?php

use App\Domain\Foods\LocalDay;
use App\Domain\Nutrition\ExpenditureFilter;
use App\Models\TargetHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function todayOf(User $u): string
{
    return LocalDay::of(now(), $u->profile->tz, $u->profile->rollover_hour);
}

function daysAgo(User $u, int $n): string
{
    return date('Y-m-d', strtotime(todayOf($u) . " -{$n} days"));
}

/**
 * Seed $days of history ending yesterday: a weigh-in every other day and food logged each day at $pct of that day's target, then move the
 * onboarding target back in time so a check-in is due. Weights follow $kgPerWeek from the onboarding weight.
 */
function seedHistory(User $u, int $days, float $pct = 1.0, float $kgPerWeek = 0.0): void
{
    $kcal = (int) TargetHistory::where('user_id', $u->id)->value('kcal');
    $start = (float) DB::table('weight_logs')->where('user_id', $u->id)->value('kg');
    DB::table('weight_logs')->where('user_id', $u->id)->delete();
    for ($i = $days; $i >= 1; $i--) {
        $date = daysAgo($u, $i);
        if ($i % 2 === 0 || $i <= 3) {
            DB::table('weight_logs')->insert(['user_id' => $u->id, 'local_date' => $date, 'kg' => round($start + $kgPerWeek / 7 * ($days - $i), 1), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('food_logs')->insert(['user_id' => $u->id, 'local_date' => $date, 'logged_at' => now(), 'meal_slot' => 'lunch', 'name' => 'Seed', 'kcal' => $kcal * $pct, 'protein' => 100,
            'carbs' => 300, 'fat' => 80, 'source' => 'quick', 'created_at' => now(), 'updated_at' => now()]);
    }
    TargetHistory::where('user_id', $u->id)->update(['effective_from' => daysAgo($u, $days)]);
}

it('saves a weigh-in, replaces the same day, and warns about a big jump without blocking it', function () {
    $u = loggedInUser();

    $this->actingAs($u)->postJson('/weights', ['kg' => 74.6])->assertCreated()->assertJsonPath('saved.kg', 74.6);
    $this->actingAs($u)->postJson('/weights', ['kg' => 74.9])->assertCreated();
    expect(DB::table('weight_logs')->where('user_id', $u->id)->where('local_date', todayOf($u))->count())->toBe(1)
        ->and((float) DB::table('weight_logs')->where('user_id', $u->id)->value('kg'))->toBe(74.9);

    $r = $this->actingAs($u)->postJson('/weights', ['kg' => 80.2, 'local_date' => daysAgo($u, 0)])->assertCreated();
    expect($r->json('note'))->toBeNull();   // same day replaces: nothing earlier to compare with

    DB::table('weight_logs')->where('user_id', $u->id)->update(['local_date' => daysAgo($u, 5)]);
    $this->actingAs($u)->postJson('/weights', ['kg' => 85.0])->assertCreated()->assertJsonPath('note', fn ($n) => str_contains((string) $n, '3 kg'));
});

it('rejects impossible weights, future dates and other people\'s weigh-ins', function () {
    $u = loggedInUser();
    $other = loggedInUser();

    $this->actingAs($u)->postJson('/weights', ['kg' => 7.4])->assertStatus(422)->assertJsonValidationErrors('kg');
    $this->actingAs($u)->postJson('/weights', ['kg' => 740])->assertStatus(422);
    $this->actingAs($u)->postJson('/weights', ['kg' => 75, 'local_date' => date('Y-m-d', strtotime('+3 days'))])->assertStatus(422);
    $id = DB::table('weight_logs')->where('user_id', $other->id)->value('id');
    $this->actingAs($u)->deleteJson("/weights/{$id}")->assertNotFound();
});

it('keeps the first weigh-in, because the trend starts from it', function () {
    $u = loggedInUser();
    $id = DB::table('weight_logs')->where('user_id', $u->id)->value('id');

    $this->actingAs($u)->deleteJson("/weights/{$id}")->assertStatus(422);
    $this->actingAs($u)->postJson('/weights', ['kg' => 75, 'local_date' => daysAgo($u, 1)])->assertCreated();
    $this->actingAs($u)->deleteJson("/weights/{$id}")->assertOk();
});

it('shows the progress page with a trend that matches the engine exactly', function () {
    $u = loggedInUser();
    seedHistory($u, 28, 1.0, 0.2);

    $page = $this->actingAs($u)->get('/progress')->assertOk()->viewData('page')['props']['data'];

    // Rebuild what the page should show straight from the pure filter.
    $weights = DB::table('weight_logs')->where('user_id', $u->id)->orderBy('local_date')->pluck('kg', 'local_date')->map(fn ($k) => (float) $k)->all();
    $kcal = (int) TargetHistory::where('user_id', $u->id)->value('kcal');
    $day = TargetHistory::where('user_id', $u->id)->first()->plan_json;
    $rows = [];
    $first = array_key_first($weights);
    for ($d = $first; $d <= todayOf($u); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $tg = (float) ($day['plan']['days'][LocalDay::dayKey($d)]['kcal'] ?? $kcal);
        $rows[] = ['date' => $d, 'weight' => $weights[$d] ?? null, 'intake' => $d < todayOf($u) && $kcal >= 0.4 * $tg ? (float) $kcal : null];
    }
    $direct = (new ExpenditureFilter(7700.0))->run($rows, reset($weights), (float) $day['prior']['tdee'], (float) ($day['prior']['sd'] ?? 350));
    $end = end($direct);
    $last = end($page['series']);

    expect($last['date'])->toBe($end['date'])->and($last['trend'])->toBe(round($end['trend'], 2))->and($last['tdee'])->toBe(round($end['tdee']))
        ->and(count($page['series']))->toBe(count($direct))->and($page['summary']['trendKg'])->toBe($last['trend']);
});

it('gives a stable summary: rate per week, calibrating state and adherence', function () {
    $u = loggedInUser();
    seedHistory($u, 28, 1.0, 0.35);

    $s = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data'];

    expect($s['summary']['ratePctPerWeek'])->toBeGreaterThan(0)->and($s['adherence'])->toMatchArray(['loggedDays7' => 7])->and($s['adherence']['weighIns14'])->toBeGreaterThanOrEqual(7)
        ->and($s['summary']['calibrating'])->toBeBool();
});

it('holds the check-in early on and explains why', function () {
    $u = loggedInUser();

    $c = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data']['checkin'];

    expect($c['status'])->toBe('hold')->and(implode(' ', $c['holdReasons']))->toContain('days of data');
});

it('proposes a target from the data, and applying it creates a new target with the explanation, once', function () {
    $u = loggedInUser();
    seedHistory($u, 28, 1.0, 0.0);   // eating the target, weight flat: expenditure is about the target, so a bulk should be nudged UP

    $c = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data']['checkin'];
    expect($c['status'])->toBe('ready')->and($c['change'])->not->toBe(0)->and(abs($c['change']))->toBeLessThanOrEqual(150);

    $before = TargetHistory::where('user_id', $u->id)->latest('id')->first();
    $this->actingAs($u)->postJson('/progress/checkin', ['kcal' => $c['proposedKcal']])->assertOk()->assertJsonPath('applied', true);
    $after = TargetHistory::where('user_id', $u->id)->latest('id')->first();

    expect($after->reason)->toBe('weekly_checkin')->and(abs($after->kcal - $c['proposedKcal']))->toBeLessThanOrEqual(2)   // the planner rounds each day's macros
        ->and($after->kcal)->not->toBe($before->kcal)
        ->and($after->plan_json['why'])->toBe($c['lines'])->and($after->plan_json['checkin']['from'])->toBe($before->kcal)
        ->and($after->protein)->toBe($before->protein);   // protein is set per kg first and does not move with calories

    // Today now uses the new target, and a second apply (double tap, stale screen) is refused: the next check-in is a week away.
    $this->actingAs($u)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('target.kcal', fn ($k) => abs($k - $after->kcal) < 400));
    $this->actingAs($u)->postJson('/progress/checkin', ['kcal' => $c['proposedKcal']])->assertStatus(422);
    expect(TargetHistory::where('user_id', $u->id)->count())->toBe(2);
});

it('refuses to apply a number the person did not see', function () {
    $u = loggedInUser();
    seedHistory($u, 28, 1.0, 0.0);

    $this->actingAs($u)->postJson('/progress/checkin', ['kcal' => 5000])->assertStatus(409);
    expect(TargetHistory::where('user_id', $u->id)->count())->toBe(1);
});

it('holds when food was not logged, instead of teaching the filter a fake deficit', function () {
    $u = loggedInUser();
    seedHistory($u, 28, 0.2, 0.0);   // 20% of target is "not really logged"

    $o = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data'];

    expect($o['adherence']['loggedDays7'])->toBe(0)->and($o['checkin']['status'])->toBe('hold')->and($o['undereating']['flag'])->toBeFalse();
});

it('nudges when intake is under 90% of target on most logged days, with foods from your own usual list, and not otherwise', function () {
    $u = loggedInUser();
    seedHistory($u, 21, 0.7, 0.0);

    $n = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data']['undereating'];
    expect($n['flag'])->toBeTrue()->and($n['lowDays'])->toBeGreaterThanOrEqual(4)->and($n['avgPct'])->toBeLessThan(90.0)->and($n['gapKcal'])->toBeGreaterThan(0)->and($n['adds'])->toBeArray();

    $u2 = loggedInUser();
    seedHistory($u2, 21, 1.0, 0.0);
    expect($this->actingAs($u2)->get('/progress')->viewData('page')['props']['data']['undereating']['flag'])->toBeFalse();
});

it('keeps progress private and requires sign-in', function () {
    $this->get('/progress')->assertRedirect('/login');
    $this->postJson('/weights', ['kg' => 75])->assertUnauthorized();
});
