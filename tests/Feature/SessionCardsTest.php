<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function scSeedWorkout($u, string $date, string $title, array $sets, string $tpl = 'BP1', string $ex = 'Bench Press'): void
{
    DB::table('hevy_connections')->updateOrInsert(['user_id' => $u->id], ['api_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hevy_templates')->updateOrInsert(['user_id' => $u->id, 'template_id' => $tpl], ['title' => $ex, 'muscle' => 'chest', 'secondary' => json_encode([])]);
    $w = DB::table('hevy_workouts')->insertGetId(['user_id' => $u->id, 'hevy_id' => 'w-' . $date . $title, 'title' => $title, 'start_at' => $date . ' 02:00:00', 'local_date' => $date, 'minutes' => 45, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($sets as $i => $s) {
        DB::table('hevy_sets')->insert(['hevy_workout_id' => $w, 'exercise_index' => 0, 'template_id' => $tpl, 'exercise_title' => $ex, 'set_index' => $i, 'type' => 'normal', 'weight_kg' => $s[0], 'reps' => $s[1]]);
    }
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Australia/Melbourne')));
afterEach(fn () => Carbon::setTestNow());

it('needs a login and says plainly when Hevy is not connected', function () {
    $this->get('/lifting/sessions')->assertRedirect('/login');
    $u = loggedInUser();
    $this->actingAs($u)->get('/lifting/sessions')->assertInertia(fn ($p) => $p->component('Sessions')->where('connected', false)->where('months', []));
});

it('shows a card per session, newest first, judged against ALL earlier history', function () {
    $u = loggedInUser();
    scSeedWorkout($u, '2025-03-01', 'Push', [[120, 3]]);        // old and heavy: today's lighter sessions are not PRs
    scSeedWorkout($u, '2026-11-10', 'Push', [[80, 8], [80, 8]]);
    scSeedWorkout($u, '2026-11-17', 'Push', [[80, 9], [80, 9]]);

    $this->actingAs($u)->get('/lifting/sessions')->assertInertia(fn ($p) => $p->component('Sessions')->where('connected', true)
        ->where('months.0.month', '2026-11')->where('months.0.sessions', 2)
        ->where('months.0.cards.0.date', '2026-11-17')->where('months.0.cards.0.volume_kg', 1440)->where('months.0.cards.0.pr_count', 0)
        ->where('months.0.cards.0.vs_last.pct', 13)->where('months.0.cards.1.volume_kg', 1280));
});

it('puts today\'s session card on Today, and nothing when the last session was earlier', function () {
    $u = loggedInUser();
    scSeedWorkout($u, '2026-11-20', 'Push', [[80, 8]]);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('sessionCard', null));

    scSeedWorkout($u, '2026-11-24', 'Push', [[85, 8]]);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('sessionCard.date', '2026-11-24')->where('sessionCard.pr_count', 1)->where('sessionCard.top_set.kg', 85));
});
