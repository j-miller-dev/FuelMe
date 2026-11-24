<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function acfLift($u, string $date, array $sets): void
{
    DB::table('hevy_connections')->updateOrInsert(['user_id' => $u->id], ['api_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hevy_templates')->updateOrInsert(['user_id' => $u->id, 'template_id' => 'BP1'], ['title' => 'Bench Press', 'muscle' => 'chest', 'secondary' => json_encode([])]);
    $w = DB::table('hevy_workouts')->insertGetId(['user_id' => $u->id, 'hevy_id' => 'w-' . $date, 'title' => 'Push', 'start_at' => $date . ' 02:00:00', 'local_date' => $date, 'minutes' => 45, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($sets as $i => $s) {
        DB::table('hevy_sets')->insert(['hevy_workout_id' => $w, 'exercise_index' => 0, 'template_id' => 'BP1', 'exercise_title' => 'Bench Press', 'set_index' => $i, 'type' => 'normal', 'weight_kg' => $s[0], 'reps' => $s[1]]);
    }
}
function acfWorkout($u, string $date, string $status): void
{
    DB::table('workout_logs')->insert(['user_id' => $u->id, 'local_date' => $date, 'type' => 'lifting', 'intensity' => 'hit', 'name' => 'x', 'minutes' => 30, 'met' => 3.5, 'net_kcal' => 100, 'status' => $status, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Australia/Melbourne')));   // a Tuesday
afterEach(fn () => Carbon::setTestNow());

it('puts a fresh personal best on Today, and a swipe clears it for good', function () {
    $u = loggedInUser();
    acfLift($u, '2026-11-10', [[80, 8]]);
    acfLift($u, '2026-11-23', [[85, 6]]);

    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('achievements.0.key', 'pr:2026-11-23:BP1')->where('achievements.0.icon', 'trophy'));

    $this->actingAs($u)->postJson('/achievements/dismiss', ['keys' => ['pr:2026-11-23:BP1']])->assertOk();
    $this->actingAs($u)->postJson('/achievements/dismiss', ['keys' => ['pr:2026-11-23:BP1']])->assertOk();   // idempotent

    expect(DB::table('achievement_dismissals')->where('user_id', $u->id)->count())->toBe(1);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('achievements', []));
});

it('shows nothing when nothing happened, and refuses a junk dismiss', function () {
    $this->postJson('/achievements/dismiss', ['keys' => ['a']])->assertStatus(401);
    $u = loggedInUser();

    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('achievements', []));
    $this->actingAs($u)->postJson('/achievements/dismiss', ['keys' => []])->assertStatus(422);
    $this->actingAs($u)->postJson('/achievements/dismiss', ['keys' => [str_repeat('x', 101)]])->assertStatus(422);
});

it('celebrates a full training week on the Monday to Wednesday after it, the first time as the first', function () {
    $u = loggedInUser();
    foreach (['2026-11-16', '2026-11-18', '2026-11-20'] as $d) {
        acfWorkout($u, $d, 'done');
    }

    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('achievements.0.title', 'First full training week')->where('achievements.0.key', 'week:train:2026-11-16'));

    acfWorkout($u, '2026-11-21', 'skipped');   // one skipped: no longer every planned session
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('achievements', []));
});

it('does not show last week\'s wins on a Thursday', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'Australia/Melbourne'));
    $u = loggedInUser();
    foreach (['2026-11-16', '2026-11-18', '2026-11-20'] as $d) {
        acfWorkout($u, $d, 'done');
    }

    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('achievements', []));
});
