<?php

use App\Services\Coach\CoachTools;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Direct rows so this file runs on its own. */
function pgConnect($u): void
{
    DB::table('hevy_connections')->updateOrInsert(['user_id' => $u->id], ['api_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hevy_templates')->updateOrInsert(['user_id' => $u->id, 'template_id' => 'BP1'], ['title' => 'Bench Press', 'muscle' => 'chest', 'secondary' => '[]']);
    DB::table('hevy_templates')->updateOrInsert(['user_id' => $u->id, 'template_id' => 'RW1'], ['title' => 'Row (Machine)', 'muscle' => 'upper_back', 'secondary' => '[]']);
}

function pgRoutine($u, string $hevyId, string $title, ?string $folderId, ?string $folderTitle, array $exercises): int
{
    $id = DB::table('hevy_routines')->insertGetId(['user_id' => $u->id, 'hevy_id' => $hevyId, 'title' => $title, 'folder_id' => $folderId, 'folder_title' => $folderTitle, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($exercises as $i => [$tpl, $name, $sets, $lo, $hi]) {
        DB::table('hevy_routine_exercises')->insert(['hevy_routine_id' => $id, 'exercise_index' => $i, 'template_id' => $tpl, 'title' => $name, 'sets' => json_encode(array_fill(0, $sets, ['type' => 'normal', 'kg' => null, 'reps' => null, 'rep_min' => $lo, 'rep_max' => $hi]))]);
    }

    return $id;
}

/** One workout with one or more exercises: [[template, title, [[kg, reps], ...]], ...] */
function pgWorkout($u, string $date, array $exercises, ?string $routineId = null, string $title = 'Day A'): void
{
    $w = DB::table('hevy_workouts')->insertGetId(['user_id' => $u->id, 'hevy_id' => 'w-' . $date . $title, 'routine_id' => $routineId, 'title' => $title, 'start_at' => $date . ' 02:00:00', 'end_at' => $date . ' 02:40:00', 'local_date' => $date, 'minutes' => 40, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($exercises as $ei => [$tpl, $name, $sets]) {
        foreach ($sets as $si => $s) {
            DB::table('hevy_sets')->insert(['hevy_workout_id' => $w, 'exercise_index' => $ei, 'template_id' => $tpl, 'exercise_title' => $name, 'set_index' => $si, 'type' => $s[2] ?? 'normal', 'weight_kg' => $s[0], 'reps' => $s[1]]);
        }
    }
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Australia/Melbourne')));
afterEach(fn () => Carbon::setTestNow());

it('needs a login and an onboarded user, and is empty without routines', function () {
    $this->get('/lifting/progression')->assertRedirect('/login');
    $u = loggedInUser();
    $this->actingAs($u)->get('/lifting/progression')->assertInertia(fn ($p) => $p->component('Progression')->where('program.connected', false)->where('program.selected', null)->has('program.routines', 0));
});

it('opens on the Rational Intensity folder and suggests a jump when the top of the range was reached', function () {
    $u = loggedInUser();
    pgConnect($u);
    pgRoutine($u, 'r-other', 'Push', 'F2', 'Other plan', [['BP1', 'Bench Press', 3, 6, 8]]);
    pgRoutine($u, 'r-a', 'Day A: Optimized (Tension)', 'F1', 'Rational Intensity P1: Optimized (Tension)', [['BP1', 'Bench Press', 3, 8, 10], ['RW1', 'Row (Machine)', 2, 8, 10], ['JR', 'Jump Rope', 1, null, null]]);
    pgWorkout($u, '2026-11-20', [['BP1', 'Bench Press', [[80, 10], [80, 10], [80, 10]]], ['RW1', 'Row (Machine)', [[50, 9], [50, 8]]]], 'r-a');
    $this->actingAs($u)->get('/lifting/progression')->assertInertia(fn ($p) => $p->component('Progression')->where('program.selected', 'F1')->has('program.folders', 2)
        ->where('program.routines.0.title', 'Day A: Optimized (Tension)')
        ->where('program.routines.0.exercises.0.next.action', 'increase')->where('program.routines.0.exercises.0.next.kg', 82.5)->where('program.routines.0.exercises.0.next.reps_low', 8)
        ->where('program.routines.0.exercises.1.next.action', 'hold')->where('program.routines.0.exercises.1.next.kg', 50)
        ->where('program.routines.0.exercises.2.next.action', 'no_load')
        ->where('program.summary.increase', 1)->where('program.summary.hold', 1)->where('program.summary.no_load', 1));
    // another folder on request, and an unknown key falls back to the default
    $this->actingAs($u)->get('/lifting/progression?folder=F2')->assertInertia(fn ($p) => $p->where('program.selected', 'F2')->where('program.routines.0.title', 'Push'));
    $this->actingAs($u)->get('/lifting/progression?folder=nope')->assertInertia(fn ($p) => $p->where('program.selected', 'F1'));
});

it('falls back to the folder you trained most recently when none is called Rational Intensity', function () {
    $u = loggedInUser();
    pgConnect($u);
    pgRoutine($u, 'r1', 'Alpha', 'FA', 'A plan', [['BP1', 'Bench Press', 3, 8, 10]]);
    pgRoutine($u, 'r2', 'Beta', 'FB', 'B plan', [['BP1', 'Bench Press', 3, 8, 10]]);
    pgWorkout($u, '2026-11-18', [['BP1', 'Bench Press', [[80, 8]]]], 'r2', 'Beta');
    $this->actingAs($u)->get('/lifting/progression')->assertInertia(fn ($p) => $p->where('program.selected', 'FB'));
});

it('uses your own smallest jump when you set one, and validates it', function () {
    $u = loggedInUser();
    pgConnect($u);
    pgRoutine($u, 'r-a', 'Day A', 'F1', 'Rational Intensity', [['BP1', 'Bench Press', 3, 8, 10]]);
    pgWorkout($u, '2026-11-20', [['BP1', 'Bench Press', [[80, 10], [80, 10], [80, 10]]]], 'r-a');
    $this->actingAs($u)->put('/lifting/exercise/BP1/step', ['step_kg' => 1.1])->assertSessionHasNoErrors();   // rounded to the nearest 0.25
    expect((float) DB::table('exercise_prefs')->value('step_kg'))->toBe(1.0);
    $this->actingAs($u)->get('/lifting/progression')->assertInertia(fn ($p) => $p->where('program.routines.0.exercises.0.next.kg', 81)->where('program.routines.0.exercises.0.step.custom', true));
    $this->actingAs($u)->get('/lifting/exercise/BP1')->assertInertia(fn ($p) => $p->where('exercise.step.kg', 1)->where('exercise.step.custom', true));
    $this->actingAs($u)->put('/lifting/exercise/BP1/step', ['step_kg' => 0.1])->assertSessionHasErrors('step_kg');
    $this->actingAs($u)->put('/lifting/exercise/BP1/step', ['step_kg' => 30])->assertSessionHasErrors('step_kg');
    $this->actingAs($u)->put('/lifting/exercise/NOPE/step', ['step_kg' => 2.5])->assertNotFound();
    $this->actingAs($u)->delete('/lifting/exercise/BP1/step');
    $this->actingAs($u)->get('/lifting/exercise/BP1')->assertInertia(fn ($p) => $p->where('exercise.step.kg', 2.5)->where('exercise.step.custom', false));
    // another user's setting is separate
    $other = loggedInUser();
    expect(DB::table('exercise_prefs')->where('user_id', $other->id)->count())->toBe(0);
});

it('leaves a labelled deload week out of the suggestion', function () {
    $u = loggedInUser();
    pgConnect($u);
    pgRoutine($u, 'r-a', 'Day A', 'F1', 'Rational Intensity', [['BP1', 'Bench Press', 3, 8, 10]]);
    pgWorkout($u, '2026-11-10', [['BP1', 'Bench Press', [[80, 8], [80, 8], [80, 8]]]], 'r-a');
    pgWorkout($u, '2026-11-18', [['BP1', 'Bench Press', [[50, 15], [50, 15]]]], 'r-a');     // an easy deload week
    DB::table('deload_weeks')->insert(['user_id' => $u->id, 'week_start' => '2026-11-16', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($u)->get('/lifting/progression')->assertInertia(fn ($p) => $p->where('program.routines.0.exercises.0.next.last.kg', 80)->where('program.routines.0.exercises.0.next.action', 'hold'));
});

it('shows the suggestion, a projection range and how the last session compared, on the routine page', function () {
    $u = loggedInUser();
    pgConnect($u);
    $id = pgRoutine($u, 'r-a', 'Day A', 'F1', 'Rational Intensity', [['BP1', 'Bench Press', 3, 8, 10], ['RW1', 'Row (Machine)', 2, 8, 10]]);
    foreach (["2026-10-13", "2026-10-20", "2026-10-27", "2026-11-03", "2026-11-10"] as $i => $d) {
        pgWorkout($u, $d, [['BP1', 'Bench Press', [[70 + $i * 1.25, 8]]], ['RW1', 'Row (Machine)', [[50, 8], [50, 8]]]], 'r-a');
    }
    // last session: bench short of its sets, row skipped, a curl added
    pgWorkout($u, '2026-11-20', [['BP1', 'Bench Press', [[75, 10]]], ['CU1', 'Curl', [[20, 12]]]], 'r-a');
    $this->actingAs($u)->get("/lifting/routines/{$id}")->assertInertia(fn ($p) => $p->component('Routine')
        ->where('routine.diff.date', '2026-11-20')->where('routine.diff.skipped', 1)->where('routine.diff.partial', 1)->where('routine.diff.extra', 1)
        ->where('routine.exercises.0.next.action', 'increase')->where('routine.exercises.0.next.kg', 77.5)
        ->where('routine.exercises.0.projection.status', 'ok')->has('routine.exercises.0.projection.weeks', 2)
        ->where('routine.exercises.0.step.kg', 2.5)->etc());
});

it('gives the coach the routines, suggestions and diffs, never inventing a routine', function () {
    $u = loggedInUser();
    pgConnect($u);
    pgRoutine($u, 'r-a', 'Day A: Optimized', 'F1', 'Rational Intensity', [['BP1', 'Bench Press', 3, 8, 10]]);
    pgWorkout($u, '2026-11-20', [['BP1', 'Bench Press', [[80, 10], [80, 10], [80, 10]]]], 'r-a');
    $t = app(CoachTools::class);
    $list = $t->run('get_routines', [], $u->fresh());
    expect($list['connected'])->toBeTrue()->and($list['routines'][0]['title'])->toBe('Day A: Optimized')->and($list['routines'][0]['last_done'])->toBe('2026-11-20');
    $one = $t->run('get_routines', ['routine' => 'day a'], $u->fresh());
    expect($one['found'])->toBeTrue()->and($one['routine']['exercises'][0]['next']['action'])->toBe('increase')->and($one['routine']['exercises'][0]['next']['kg'])->toBe(82.5)->and($one['note'])->toContain('not a prescription');
    expect($t->run('get_routines', ['routine' => 'Day Z'], $u->fresh())['found'])->toBeFalse();
    expect($t->run('get_routines', [], loggedInUser())['connected'])->toBeFalse();
});
