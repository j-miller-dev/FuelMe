<?php

use App\Jobs\SyncHevy;
use App\Services\Coach\CoachTools;
use App\Services\Hevy\HevySync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const HEVY_KEY = 'hevy-test-key-1234567890';

function hevyWorkout(string $id, string $start, array $sets = [[100, 8]], string $title = 'Legs'): array
{
    $mk = fn ($start) => gmdate('Y-m-d\TH:i:s\Z', strtotime($start));

    return ['id' => $id, 'title' => $title, 'start_time' => $mk($start), 'end_time' => $mk($start . ' +40 minutes'), 'exercises' => [
        ['index' => 0, 'title' => 'Leg Press', 'exercise_template_id' => 'LP1', 'sets' => array_map(fn ($s, $i) => ['index' => $i, 'type' => 'normal', 'weight_kg' => $s[0], 'reps' => $s[1]], $sets, array_keys($sets))]]];
}

function hevyFake(array $workouts, array $events = []): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);   // fresh stubs: the first matching fake wins, so a second call would be ignored
    Http::fake([
        '*/v1/user/info' => Http::response(['data' => ['name' => 'x']]),
        '*/v1/routine_folders*' => Http::response(['page' => 1, 'page_count' => 1, 'routine_folders' => []]),
        '*/v1/routines*' => Http::response(['page' => 1, 'page_count' => 1, 'routines' => []]),
        '*/v1/exercise_templates*' => Http::response(['page' => 1, 'page_count' => 1, 'exercise_templates' => [['id' => 'LP1', 'title' => 'Leg Press', 'primary_muscle_group' => 'quadriceps', 'secondary_muscle_groups' => ['glutes']]]]),
        '*/v1/workouts/events*' => Http::response(['page' => 1, 'page_count' => 1, 'events' => $events]),
        '*/v1/workouts*' => Http::response(['page' => 1, 'page_count' => 1, 'workouts' => $workouts]),
    ]);
}

function hevyConnect($test, $u): void
{
    $test->actingAs($u)->post('/lifting/connect', ['api_key' => HEVY_KEY])->assertSessionHasNoErrors();
}

it('connects only with a key Hevy accepts, stores it encrypted, and starts a sync', function () {
    Bus::fake();
    $u = loggedInUser();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*/v1/user/info' => Http::response(['error' => 'no'], 401)]);
    $this->actingAs($u)->post('/lifting/connect', ['api_key' => HEVY_KEY])->assertSessionHasErrors('api_key');
    expect(DB::table('hevy_connections')->count())->toBe(0);

    hevyFake([]);
    hevyConnect($this, $u);
    expect(DB::table('hevy_connections')->value('api_key'))->not->toContain(HEVY_KEY);
    Bus::assertDispatched(SyncHevy::class);
    $this->actingAs($u)->get('/lifting')->assertInertia(fn ($p) => $p->component('Lifting')->where('connection.last_error', null)->missing('connection.api_key'));
    Http::assertSent(fn ($r) => $r->hasHeader('api-key', HEVY_KEY) || ! str_contains($r->url(), 'hevyapp'));
});

it('syncs everything the first time, is safe to repeat, and keeps sets per workout', function () {
    Bus::fake();
    $u = loggedInUser();
    hevyFake([hevyWorkout('w1', '2026-09-01 02:00', [[100, 8], [100, 7]]), hevyWorkout('w2', '2026-09-08 02:00')]);
    hevyConnect($this, $u);
    $r = app(HevySync::class)->sync($u->fresh());
    expect($r)->toMatchArray(['ok' => true, 'workouts' => 2])->and(DB::table('hevy_workouts')->count())->toBe(2)->and(DB::table('hevy_sets')->count())->toBe(3)->and(DB::table('hevy_templates')->count())->toBe(1);
    // an edit on Hevy's side replaces the sets rather than duplicating them
    DB::table('hevy_connections')->update(['cursor_at' => null]);
    hevyFake([hevyWorkout('w1', '2026-09-01 02:00', [[105, 8]]), hevyWorkout('w2', '2026-09-08 02:00')]);
    app(HevySync::class)->sync($u->fresh());
    expect(DB::table('hevy_workouts')->count())->toBe(2)->and(DB::table('hevy_sets')->count())->toBe(2)->and((float) DB::table('hevy_sets')->orderBy('id')->value('weight_kg'))->toBe(105.0);
});

it('then reads only changes: updates and deletes', function () {
    Bus::fake();
    $u = loggedInUser();
    hevyFake([hevyWorkout('w1', '2026-09-01 02:00'), hevyWorkout('w2', '2026-09-08 02:00')]);
    hevyConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    expect(DB::table('hevy_connections')->value('cursor_at'))->not->toBeNull();

    hevyFake([], [['type' => 'deleted', 'id' => 'w1', 'deleted_at' => '2026-09-20T00:00:00Z'], ['type' => 'updated', 'workout' => hevyWorkout('w3', '2026-09-15 02:00')]]);
    $r = app(HevySync::class)->sync($u->fresh());
    expect($r)->toMatchArray(['ok' => true, 'workouts' => 1, 'deleted' => 1])->and(DB::table('hevy_workouts')->pluck('hevy_id')->sort()->values()->all())->toBe(['w2', 'w3']);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/workouts/events') && str_contains($r->url(), 'since='));
});

it('records a rejected key without echoing it', function () {
    Bus::fake();
    $u = loggedInUser();
    hevyFake([]);
    hevyConnect($this, $u);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*' => Http::response(['error' => 'bad'], 401)]);
    $r = app(HevySync::class)->sync($u->fresh());
    expect($r['ok'])->toBeFalse()->and(DB::table('hevy_connections')->value('last_error'))->toContain('did not accept')->not->toContain(HEVY_KEY);
});

it('confirms the planned lifting session for the day instead of adding a second one', function () {
    Bus::fake();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-23 10:00', 'Australia/Melbourne'));   // a Monday: the HIT session is planned
    $u = loggedInUser();
    $lifting = fn () => DB::table('workout_logs')->where('user_id', $u->id)->where('type', 'lifting')->where('local_date', '2026-11-23');
    expect($this->actingAs($u)->get('/')->assertOk())->not->toBeNull();

    hevyFake([hevyWorkout('w9', '2026-11-23 01:00')]);   // 12:00 Melbourne
    hevyConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $rows = $lifting()->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->status)->toBe('done')->and((int) $rows[0]->minutes)->toBe(40)->and($rows[0]->name)->toBe('Legs');
    // again: nothing new
    DB::table('hevy_connections')->update(['cursor_at' => null]);
    app(HevySync::class)->sync($u->fresh());
    expect($lifting()->count())->toBe(1);
});

it('does not touch days older than two weeks', function () {
    Bus::fake();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-23 10:00', 'Australia/Melbourne'));
    $u = loggedInUser();
    hevyFake([hevyWorkout('old', '2026-09-01 02:00')]);
    hevyConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    expect(DB::table('workout_logs')->where('local_date', '2026-09-01')->count())->toBe(0);
});

it('shows progress per lift, and the coach tool reads it, only for the owner', function () {
    Bus::fake();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-23 10:00', 'Australia/Melbourne'));
    $u = loggedInUser();
    $ws = [];
    foreach ([[100, 8], [100, 8], [105, 8], [105, 9]] as $i => $s) {
        $ws[] = hevyWorkout("w$i", '2026-10-' . sprintf('%02d', 5 + $i * 7) . ' 02:00', [$s]);
    }
    hevyFake($ws);
    hevyConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());

    $o = app(CoachTools::class)->run('get_lifting', ['days' => 90], $u->fresh());
    expect($o['connected'])->toBeTrue()->and($o['exercises'][0]['title'])->toBe('Leg Press')->and($o['exercises'][0]['status'])->toBe('progressing')
        ->and($o['exercises'][0]['beat_the_log'][0])->toMatchArray(['kg' => 105.0, 'reps' => 10])->and($o['strength_vs_bodyweight']['exercises'])->toBe(1);
    $this->actingAs($u)->get('/lifting')->assertInertia(fn ($p) => $p->where('data.exercises.0.status', 'progressing')->has('data.recent', 4)->where('data.recent.0.effort.unlabelled', 1));

    $other = loggedInUser();
    expect(app(CoachTools::class)->run('get_lifting', [], $other)['connected'])->toBeFalse();
});

it('disconnects, and deletes history only when asked', function () {
    Bus::fake();
    $u = loggedInUser();
    hevyFake([hevyWorkout('w1', '2026-09-01 02:00')]);
    hevyConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $this->actingAs($u)->delete('/lifting/connection')->assertRedirect();
    expect(DB::table('hevy_connections')->count())->toBe(0)->and(DB::table('hevy_workouts')->count())->toBe(1);
    hevyConnect($this, $u);
    $this->actingAs($u)->delete('/lifting/connection?delete_data=1');
    expect(DB::table('hevy_workouts')->count())->toBe(0)->and(DB::table('hevy_sets')->count())->toBe(0);
});

it('retries a busy Hevy once instead of failing the sync', function () {
    Bus::fake();
    $u = loggedInUser();
    hevyFake([]);
    hevyConnect($this, $u);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        '*/v1/exercise_templates*' => Http::response(['page' => 1, 'page_count' => 1, 'exercise_templates' => []]),
        '*/v1/routine*' => Http::response(['error' => 'not found'], 404),   // routines missing must not fail the workout sync
        '*/v1/workouts*' => Http::sequence()->push('busy', 429)->push(['page' => 1, 'page_count' => 1, 'workouts' => [hevyWorkout('w1', '2026-09-01 02:00')]]),
    ]);
    expect(app(HevySync::class)->sync($u->fresh())['ok'])->toBeTrue()->and(DB::table('hevy_workouts')->count())->toBe(1);
});

it('compares a session with your effort plan, and says nothing when no effort was logged', function () {
    Bus::fake();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-25 10:00', 'Australia/Melbourne'));
    $u = loggedInUser();
    $hard = hevyWorkout('hard', '2026-11-23 02:00', [[100, 8]]);
    $hard['exercises'][0]['sets'][0]['rpe'] = 10;                      // 0 in reserve
    $plain = hevyWorkout('plain', '2026-11-24 02:00', [[100, 8]]);     // no effort logged
    hevyFake([$hard, $plain]);
    hevyConnect($this, $u);
    app(HevySync::class)->sync($u->fresh());
    $this->actingAs($u)->put('/lifting/effort-ramp', ['ramp' => ['start' => '2026-11-23', 'weeks' => [[2, 3]]]])->assertSessionHasNoErrors();

    $this->actingAs($u->fresh())->get('/lifting')->assertInertia(fn ($p) => $p
        ->where('data.recent.0.vs_plan.verdict', 'unknown')
        ->where('data.recent.1.vs_plan.verdict', 'harder')->where('data.recent.1.vs_plan.avg_rir', 0)->where('data.recent.1.vs_plan.planned', '2-3 reps in reserve'));
});
