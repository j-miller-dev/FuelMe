<?php

use App\Domain\Training\HevyMapper;
use App\Services\Hevy\HevySync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function rtRoutine(string $id, string $title, array $exercises, ?string $folder = 'F1'): array
{
    return ['id' => $id, 'title' => $title, 'folder_id' => $folder, 'updated_at' => '2026-11-20T01:00:00Z', 'exercises' => $exercises];
}

function rtEx(string $tpl, string $title, array $sets, array $extra = []): array
{
    return $extra + ['index' => 0, 'title' => $title, 'exercise_template_id' => $tpl, 'rest_seconds' => 120, 'sets' => array_map(fn ($s, $i) => ['index' => $i, 'type' => $s[2] ?? 'normal', 'weight_kg' => $s[0], 'reps' => $s[1]], $sets, array_keys($sets))];
}

/** Fakes Hevy with these routines and no workouts. Fresh stubs each call: the first matching fake wins. */
function rtFake(array $routines, array $folders = [['id' => 'F1', 'title' => 'HIT']], array $workouts = []): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        '*/v1/user/info' => Http::response(['data' => ['name' => 'x']]),
        '*/v1/exercise_templates*' => Http::response(['page' => 1, 'page_count' => 1, 'exercise_templates' => [['id' => 'BP1', 'title' => 'Bench Press', 'primary_muscle_group' => 'chest', 'secondary_muscle_groups' => ['triceps']]]]),
        '*/v1/routine_folders*' => Http::response(['page' => 1, 'page_count' => 1, 'routine_folders' => $folders]),
        '*/v1/routines*' => Http::response(['page' => 1, 'page_count' => 1, 'routines' => $routines]),
        '*/v1/workouts/events*' => Http::response(['page' => 1, 'page_count' => 1, 'events' => []]),
        '*/v1/workouts*' => Http::response(['page' => 1, 'page_count' => 1, 'workouts' => $workouts]),
    ]);
}

function rtConnect($test, $u): void
{
    Bus::fake();
    $test->actingAs($u)->post('/lifting/connect', ['api_key' => 'routines-test-key-12345'])->assertSessionHasNoErrors();
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Australia/Melbourne')));
afterEach(fn () => Carbon::setTestNow());

it('maps a routine tolerantly: rep ranges, empty weights, missing fields', function () {
    $m = HevyMapper::routine(['id' => 'r1', 'title' => 'Push', 'folder_id' => 'F1', 'notes' => '  heavy day  ', 'exercises' => [
        ['index' => 0, 'title' => 'Bench', 'exercise_template_id' => 'BP1', 'rest_seconds' => 90, 'notes' => 'pause', 'supersets_id' => 3, 'sets' => [
            ['type' => 'normal', 'weight_kg' => 80, 'rep_range' => ['start' => 6, 'end' => 8]], ['type' => 'weird', 'reps' => 10], ['type' => 'warmup', 'weight_kg' => null, 'reps' => null]]],
        ['title' => 'Mystery'],
    ]], ['F1' => 'HIT']);
    expect($m['folder_title'])->toBe('HIT')->and($m['notes'])->toBe('heavy day')
        ->and($m['exercises'][0]['sets'][0])->toMatchArray(['kg' => 80.0, 'reps' => null, 'rep_min' => 6, 'rep_max' => 8])
        ->and($m['exercises'][0]['sets'][1]['type'])->toBe('normal')->and($m['exercises'][0]['superset_id'])->toBe('3')->and($m['exercises'][0]['notes'])->toBe('pause')
        ->and($m['exercises'][1])->toMatchArray(['template_id' => '', 'sets' => [], 'rest_seconds' => null]);
    expect(HevyMapper::routine(['title' => 'no id']))->toBeNull();
    expect(HevyMapper::routine(['id' => 'r2', 'rest_seconds' => 99999, 'exercises' => [['rest_seconds' => 99999]]])['exercises'][0]['rest_seconds'])->toBe(3600);
});

it('syncs routines, replaces them on the next sync, and drops ones deleted in Hevy', function () {
    $u = loggedInUser();
    rtFake([rtRoutine('r1', 'Push', [rtEx('BP1', 'Bench Press', [[80, 8], [80, 8]])]), rtRoutine('r2', 'Legs', [rtEx('SQ1', 'Squat', [[100, 5]])])]);
    rtConnect($this, $u);
    expect(app(HevySync::class)->sync($u->fresh())['ok'])->toBeTrue();
    expect(DB::table('hevy_routines')->count())->toBe(2)->and(DB::table('hevy_routine_exercises')->count())->toBe(2)->and(DB::table('hevy_routines')->where('hevy_id', 'r1')->value('folder_title'))->toBe('HIT');

    rtFake([rtRoutine('r1', 'Push Day', [rtEx('BP1', 'Bench Press', [[82.5, 6]])])]);   // renamed, one set, and r2 is gone
    DB::table('hevy_connections')->update(['cursor_at' => null]);
    app(HevySync::class)->sync($u->fresh());
    expect(DB::table('hevy_routines')->pluck('title')->all())->toBe(['Push Day'])->and(DB::table('hevy_routine_exercises')->count())->toBe(1);
    expect(json_decode(DB::table('hevy_routine_exercises')->value('sets'), true)[0]['kg'])->toBe(82.5);
});

it('never wipes stored routines on a response it does not recognise or an error, and says so', function () {
    $u = loggedInUser();
    rtFake([rtRoutine('r1', 'Push', [rtEx('BP1', 'Bench Press', [[80, 8]])])]);
    rtConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());

    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*/v1/routine_folders*' => Http::response(['page' => 1, 'page_count' => 1, 'routine_folders' => []]), '*/v1/routines*' => Http::response(['data' => []]),
        '*/v1/exercise_templates*' => Http::response(['page' => 1, 'page_count' => 1, 'exercise_templates' => []]), '*/v1/workouts*' => Http::response(['page' => 1, 'page_count' => 1, 'workouts' => []])]);
    DB::table('hevy_connections')->update(['cursor_at' => null]);
    expect(app(HevySync::class)->sync($u->fresh())['ok'])->toBeTrue();   // workouts are fine
    expect(DB::table('hevy_routines')->count())->toBe(1)->and(DB::table('hevy_connections')->value('routines_error'))->toContain('shape');
    $this->actingAs($u)->get('/lifting/routines')->assertInertia(fn ($p) => $p->where('data.error', fn ($e) => str_contains($e, 'shape'))->has('data.routines', 1));

    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*/v1/routine*' => Http::response('nope', 404), '*/v1/exercise_templates*' => Http::response(['page' => 1, 'page_count' => 1, 'exercise_templates' => []]), '*/v1/workouts*' => Http::response(['page' => 1, 'page_count' => 1, 'workouts' => []])]);
    DB::table('hevy_connections')->update(['cursor_at' => null]);
    expect(app(HevySync::class)->sync($u->fresh())['ok'])->toBeTrue()->and(DB::table('hevy_routines')->count())->toBe(1);
});

it('lists routines with folder, counts, muscles and when you last did each (by routine id, else by title)', function () {
    $u = loggedInUser();
    $w = ['id' => 'w1', 'title' => 'Whatever', 'routine_id' => 'r1', 'start_time' => '2026-11-20T02:00:00Z', 'end_time' => '2026-11-20T02:40:00Z', 'exercises' => [rtEx('BP1', 'Bench Press', [[80, 8]])]];
    $w2 = ['id' => 'w2', 'title' => 'Legs', 'start_time' => '2026-11-18T02:00:00Z', 'end_time' => '2026-11-18T02:40:00Z', 'exercises' => [rtEx('SQ1', 'Squat', [[100, 5]])]];   // no routine id: matched by title
    rtFake([rtRoutine('r1', 'Push', [rtEx('BP1', 'Bench Press', [[80, 8], [80, 8], [60, 10, 'warmup']])]), rtRoutine('r2', 'Legs', [rtEx('SQ1', 'Squat', [[100, 5]])]), rtRoutine('r3', 'Never done', [rtEx('BP1', 'Bench Press', [[50, 10]])], null)], [['id' => 'F1', 'title' => 'HIT']], [$w, $w2]);
    rtConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $this->actingAs($u)->get('/lifting/routines')->assertInertia(fn ($p) => $p->component('Routines')->where('connected', true)
        ->where('data.routines.0.title', 'Legs')->where('data.routines.0.last_done', '2026-11-18')
        ->where('data.routines.1.title', 'Push')->where('data.routines.1.working_sets', 2)->where('data.routines.1.exercises', 1)->where('data.routines.1.muscles', ['chest'])->where('data.routines.1.last_done', '2026-11-20')
        ->where('data.routines.2.title', 'Never done')->where('data.routines.2.last_done', null));
});

it('shows each exercise with the plan, what you did last time, what to beat, your best and your notes', function () {
    $u = loggedInUser();
    $mk = fn ($id, $date, $sets) => ['id' => $id, 'title' => 'Push', 'routine_id' => 'r1', 'start_time' => $date . 'T02:00:00Z', 'end_time' => $date . 'T02:40:00Z', 'exercises' => [rtEx('BP1', 'Bench Press', $sets)]];
    rtFake([rtRoutine('r1', 'Push', [rtEx('BP1', 'Bench Press', [[80, 8], [80, 8]], ['notes' => 'Pause on chest'])])], [['id' => 'F1', 'title' => 'HIT']],
        [$mk('a', '2026-11-03', [[80, 8]]), $mk('b', '2026-11-10', [[80, 9]]), $mk('c', '2026-11-17', [[82.5, 8]])]);
    rtConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'cue', 'body' => 'Elbows tucked.']);
    $id = DB::table('hevy_routines')->value('id');
    $this->actingAs($u)->get("/lifting/routines/{$id}")->assertInertia(fn ($p) => $p->component('Routine')->where('routine.title', 'Push')->where('routine.last_done', '2026-11-17')
        ->where('routine.exercises.0.title', 'Bench Press')->where('routine.exercises.0.hevy_note', 'Pause on chest')->has('routine.exercises.0.planned', 2)
        ->where('routine.exercises.0.last.date', '2026-11-17')->where('routine.exercises.0.last.sets.0.kg', 82.5)
        ->where('routine.exercises.0.beat_the_log.0.reps', 9)->where('routine.exercises.0.heaviest.kg', 82.5)->where('routine.exercises.0.status', 'progressing')
        ->where('routine.exercises.0.notes.0.body', 'Elbows tucked.')->where('routine.exercises.0.linkable', true));
});

it('says nothing about last time for a lift you have never logged, and never invents a beat-the-log target', function () {
    $u = loggedInUser();
    rtFake([rtRoutine('r1', 'New', [rtEx('ZZ9', 'Brand New Lift', [[20, 12]])])]);
    rtConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $id = DB::table('hevy_routines')->value('id');
    $this->actingAs($u)->get("/lifting/routines/{$id}")->assertInertia(fn ($p) => $p->where('routine.exercises.0.last', null)->where('routine.exercises.0.beat_the_log', [])->where('routine.exercises.0.heaviest', null)->where('routine.exercises.0.status', null)->where('routine.exercises.0.linkable', false));
});

it('needs a login, is private per user, and is removed with "delete synced data"', function () {
    $this->get('/lifting/routines')->assertRedirect('/login');
    $u = loggedInUser();
    $this->actingAs($u)->get('/lifting/routines')->assertInertia(fn ($p) => $p->where('connected', false)->where('data', null));
    rtFake([rtRoutine('r1', 'Push', [rtEx('BP1', 'Bench Press', [[80, 8]])])]);
    rtConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $id = DB::table('hevy_routines')->value('id');
    $other = loggedInUser();
    $this->actingAs($other)->get("/lifting/routines/{$id}")->assertNotFound();
    $this->actingAs($u)->delete('/lifting/connection?delete_data=1');
    expect(DB::table('hevy_routines')->count())->toBe(0)->and(DB::table('hevy_routine_exercises')->count())->toBe(0);
});

function rfSeed($u, string $folderId, string $folder, string $title): void
{
    DB::table('hevy_connections')->updateOrInsert(['user_id' => $u->id], ['api_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hevy_routines')->insert(['user_id' => $u->id, 'hevy_id' => 'r-' . $title, 'title' => $title, 'folder_id' => $folderId, 'folder_title' => $folder, 'created_at' => now(), 'updated_at' => now()]);
}

it('hides old program folders by default, lets you choose your own, and resets to automatic', function () {
    $u = loggedInUser();
    rfSeed($u, '10', 'Rational Intensity P1: Optimized (Tension)', 'Day A');
    rfSeed($u, '11', 'Rational Intensity P2: Overload', 'Day A2');
    rfSeed($u, '20', 'PHASE 1: ANATOMICAL ADAPTATION', 'Old Day A');

    $titles = fn () => collect($this->actingAs($u)->get('/lifting/routines')->viewData('page')['props']['data']['routines'])->pluck('title')->all();
    expect($titles())->toBe(['Day A', 'Day A2']);

    $this->actingAs($u)->put('/lifting/routines/folders', ['keys' => ['20']])->assertRedirect();
    expect($titles())->toBe(['Old Day A']);

    $this->actingAs($u)->delete('/lifting/routines/folders')->assertRedirect();
    expect($titles())->toBe(['Day A', 'Day A2']);

    // the progression page and the coach list follow the same choice
    $this->actingAs($u)->get('/lifting/progression')->assertInertia(fn ($p) => $p->where('program.folders.0.title', 'Rational Intensity P1: Optimized (Tension)')->has('program.folders', 2));
    expect(array_column(app(\App\Services\Coach\CoachTools::class)->run('get_routines', [], $u)['routines'], 'title'))->toBe(['Day A', 'Day A2']);
});

it('refuses a junk folder choice', function () {
    $u = loggedInUser();
    rfSeed($u, '10', 'HIT', 'Day A');

    $this->actingAs($u)->putJson('/lifting/routines/folders', [])->assertStatus(422);
    $this->actingAs($u)->putJson('/lifting/routines/folders', ['keys' => [str_repeat('x', 65)]])->assertStatus(422);
});
