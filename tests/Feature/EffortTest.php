<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

function effortToday($test, User $u): array
{
    return $test->actingAs($u)->get('/')->assertOk()->viewData('page')['props'];
}

it('saves, shows and clears your effort plan, and rejects nonsense', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Australia/Melbourne'));
    $u = loggedInUser();
    $ramp = ['start' => '2026-11-23', 'weeks' => [[2, 3], [1, 2], [0, 1]]];

    $this->actingAs($u)->put('/lifting/effort-ramp', ['ramp' => ['start' => '2026-11-23', 'weeks' => [[3, 2]]]])->assertSessionHasErrors('ramp');
    expect($u->profile->fresh()->effort_ramp)->toBeNull();

    $this->actingAs($u)->put('/lifting/effort-ramp', ['ramp' => $ramp])->assertSessionHasNoErrors();
    $this->actingAs($u->fresh())->get('/lifting')->assertInertia(fn ($p) => $p->where('ramp.weeks.1', [1, 2])->where('rampNow.week', 1)->where('rampNow.label', '2-3 reps in reserve'));
    expect(effortToday($this, $u->fresh())['training']['effort_plan'])->toMatchArray(['week' => 1, 'min' => 2, 'max' => 3]);

    Carbon::setTestNow(Carbon::parse('2026-12-08 10:00', 'Australia/Melbourne'));
    expect(effortToday($this, $u->fresh())['training']['effort_plan']['label'])->toBe('to failure or 1 rep in reserve');

    $this->actingAs($u)->delete('/lifting/effort-ramp')->assertSessionHasNoErrors();
    expect($u->profile->fresh()->effort_ramp)->toBeNull()->and(effortToday($this, $u)['training']['effort_plan'])->toBeNull();
});

it('keeps the effort plan private to its owner', function () {
    $a = loggedInUser();
    $b = loggedInUser();
    $this->actingAs($a)->put('/lifting/effort-ramp', ['ramp' => ['start' => '2026-11-23', 'weeks' => [[2, 3]]]]);
    expect($b->profile->fresh()->effort_ramp)->toBeNull();
    $this->actingAs($b)->get('/lifting')->assertInertia(fn ($p) => $p->where('ramp', null));
});

it('records how hard a session was, can change or clear it, and rejects values outside 1-10', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 05:00', 'Australia/Melbourne'));
    $u = loggedInUser();
    $t = collect(effortToday($this, $u)['training']['sessions'])->firstWhere('type', 'lifting');
    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $t['template_id'], 'action' => 'done'])->assertOk();
    $s = fn () => collect(effortToday($this, $u)['training']['sessions'])->firstWhere('type', 'lifting');
    $id = $s()['id'];
    expect($s()['rpe'])->toBeNull();

    $this->actingAs($u)->patchJson("/workouts/{$id}", ['rpe' => 11])->assertStatus(422);
    $this->actingAs($u)->patchJson("/workouts/{$id}", ['rpe' => 8])->assertOk();
    expect((float) $s()['rpe'])->toBe(8.0);
    $this->actingAs($u)->patchJson("/workouts/{$id}", ['rpe' => 6])->assertOk();
    expect((float) $s()['rpe'])->toBe(6.0);
    $this->actingAs($u)->patchJson("/workouts/{$id}", ['rpe' => null])->assertOk();
    expect($s()['rpe'])->toBeNull();

    $other = loggedInUser();
    $this->actingAs($other)->patchJson("/workouts/{$id}", ['rpe' => 9])->assertStatus(404);
});

it('shows weekly load from rated sessions only', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-25 10:00', 'Australia/Melbourne'));   // Wednesday
    $u = loggedInUser();
    $t = collect(effortToday($this, $u)['training']['sessions'])->firstWhere('type', 'lifting');
    $this->actingAs($u)->postJson('/workouts/template-session', ['template_id' => $t['template_id'], 'action' => 'done'])->assertOk();   // makes real rows to copy columns from
    $base = (array) DB::table('workout_logs')->where('user_id', $u->id)->first();
    unset($base['id']);
    DB::table('workout_logs')->where('user_id', $u->id)->delete();
    $ins = fn (string $date, int $min, ?float $rpe) => DB::table('workout_logs')->insert(array_merge($base, [
        'local_date' => $date, 'minutes' => $min, 'rpe' => $rpe, 'status' => 'done', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
    ]));
    $ins('2026-11-23', 60, 7.0);    // this week, rated: 420
    $ins('2026-11-24', 40, null);   // this week, unrated
    $ins('2026-11-17', 50, 8.0);    // last week, rated: 400

    $this->actingAs($u)->get('/training')->assertInertia(fn ($p) => $p->where('load.this_week', ['load' => 420, 'rated' => 1, 'unrated' => 1])->where('load.last_week', ['load' => 400, 'rated' => 1, 'unrated' => 0]));
});
