<?php

use App\Services\Coach\CoachTools;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Direct rows (not the Hevy HTTP fakes) so this file runs on its own. Each session = one Bench Press (template BP1) with the given [kg, reps] sets. */
function liftSeed($u, string $date, array $sets, string $tpl = 'BP1', string $title = 'Bench Press'): void
{
    DB::table('hevy_connections')->updateOrInsert(['user_id' => $u->id], ['api_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hevy_templates')->updateOrInsert(['user_id' => $u->id, 'template_id' => $tpl], ['title' => $title, 'muscle' => 'chest', 'secondary' => json_encode(['triceps'])]);
    $w = DB::table('hevy_workouts')->insertGetId(['user_id' => $u->id, 'hevy_id' => 'w-' . $date . $tpl, 'title' => 'Push', 'start_at' => $date . ' 02:00:00', 'end_at' => $date . ' 02:40:00', 'local_date' => $date, 'minutes' => 40, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($sets as $i => $s) {
        DB::table('hevy_sets')->insert(['hevy_workout_id' => $w, 'exercise_index' => 0, 'template_id' => $tpl, 'exercise_title' => $title, 'set_index' => $i, 'type' => $s[2] ?? 'normal', 'weight_kg' => $s[0], 'reps' => $s[1]]);
    }
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Australia/Melbourne')));
afterEach(fn () => Carbon::setTestNow());

it('needs a login, and says so plainly when Hevy is not connected', function () {
    $this->get('/lifting/best')->assertRedirect('/login');
    $u = loggedInUser();
    $this->actingAs($u)->get('/lifting/best')->assertInertia(fn ($p) => $p->component('BestLifts')->where('connected', false)->where('board', null));
});

it('lists your records from ALL synced history, not just the last 180 days', function () {
    $u = loggedInUser();
    liftSeed($u, '2025-02-01', [[120, 3]]);               // an old best, well outside the Lifting page's window
    liftSeed($u, '2026-11-10', [[100, 3], [60, 10, 'warmup']]);   // lighter in every way, so no PR
    $this->actingAs($u)->get('/lifting/best')->assertInertia(fn ($p) => $p->component('BestLifts')->where('connected', true)
        ->where('board.exercises.0.title', 'Bench Press')->where('board.exercises.0.heaviest.kg', 120)->where('board.exercises.0.heaviest.date', '2025-02-01')
        ->where('board.exercises.0.sessions', 2)->where('board.new_count', 0));
});

it('flags a fresh PR and counts it', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-10-20', [[80, 8]]);
    liftSeed($u, '2026-11-20', [[85, 6]]);
    $this->actingAs($u)->get('/lifting/best')->assertInertia(fn ($p) => $p->where('board.new_count', 1)->where('board.exercises.0.heaviest.new', true)->where('board.exercises.0.has_new', true));
});

it('shows one exercise with records, status, beat-the-log and its recent sets', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-11-03', [[80, 8]]);
    liftSeed($u, '2026-11-10', [[80, 9]]);
    liftSeed($u, '2026-11-17', [[82.5, 8]]);
    $this->actingAs($u)->get('/lifting/exercise/BP1')->assertInertia(fn ($p) => $p->component('Exercise')->where('exercise.title', 'Bench Press')
        ->where('exercise.records.heaviest.kg', 82.5)->where('exercise.status.sessions', 3)->has('exercise.trend', 3)
        ->where('exercise.recent.0.date', '2026-11-17')->where('exercise.beat_the_log.0.reps', 9));
    $this->actingAs($u)->get('/lifting/exercise/NOPE')->assertNotFound();
});

it('keeps your notes per exercise: add, edit, delete, and nobody else can touch them', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-11-17', [[80, 8]]);
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'cue', 'body' => '  Elbows tucked, pause on the chest.  '])->assertSessionHasNoErrors();
    $row = DB::table('exercise_notes')->first();
    expect($row->body)->toBe('Elbows tucked, pause on the chest.')->and($row->kind)->toBe('cue')->and($row->local_date)->toBe('2026-11-24');
    $this->actingAs($u)->get('/lifting/exercise/BP1')->assertInertia(fn ($p) => $p->where('exercise.notes.0.body', 'Elbows tucked, pause on the chest.'));

    $this->actingAs($u)->patch("/lifting/notes/{$row->id}", ['kind' => 'worked', 'body' => 'Slower negative fixed the shoulder.'])->assertSessionHasNoErrors();
    expect(DB::table('exercise_notes')->first())->toMatchObject(['kind' => 'worked', 'body' => 'Slower negative fixed the shoulder.']);

    $other = loggedInUser();
    $this->actingAs($other)->patch("/lifting/notes/{$row->id}", ['body' => 'hacked'])->assertNotFound();
    $this->actingAs($other)->delete("/lifting/notes/{$row->id}")->assertNotFound();
    expect(DB::table('exercise_notes')->count())->toBe(1);

    $this->actingAs($u)->delete("/lifting/notes/{$row->id}")->assertRedirect();
    expect(DB::table('exercise_notes')->count())->toBe(0);
});

it('validates notes: no empty text, a known kind, a length cap, and only for a known exercise', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-11-17', [[80, 8]]);
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'cue', 'body' => '   '])->assertSessionHasErrors('body');
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'cue', 'body' => str_repeat('x', 601)])->assertSessionHasErrors('body');
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'shout', 'body' => 'hi'])->assertSessionHasErrors('kind');
    $this->actingAs($u)->post('/lifting/exercise/UNKNOWN/notes', ['kind' => 'cue', 'body' => 'hi'])->assertNotFound();
    expect(DB::table('exercise_notes')->count())->toBe(0);
});

it('survives deleting the synced history: your notes stay and the page still opens', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-11-17', [[80, 8]]);
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'cue', 'body' => 'Keep it tight.']);
    $this->actingAs($u)->delete('/lifting/connection?delete_data=1');
    $this->actingAs($u)->get('/lifting/exercise/BP1')->assertOk()->assertInertia(fn ($p) => $p->where('exercise.records', null)->where('exercise.notes.0.body', 'Keep it tight.'));
});

it('links your own principle cards that match the exercise', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-11-17', [[80, 8]]);
    $note = "---\nid: bench-leg-drive\nsource: My notes\ntags: [bench, chest]\ngrade: strong\nstance: follow\n---\n# Bench press leg drive\nDrive through the legs on every bench press rep.";
    $this->actingAs($u)->post('/principles/import', ['files' => [UploadedFile::fake()->createWithContent('b.md', $note)], 'full_sync' => false], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($u)->get('/lifting/exercise/BP1')->assertInertia(fn ($p) => $p->where('exercise.principles.0.title', 'Bench press leg drive'));
});

it('gives the coach your records and notes, never a guess for an unknown lift', function () {
    $u = loggedInUser();
    liftSeed($u, '2026-10-20', [[80, 8]]);
    liftSeed($u, '2026-11-20', [[85, 6]]);
    $this->actingAs($u)->post('/lifting/exercise/BP1/notes', ['kind' => 'cue', 'body' => 'Pause on the chest.']);
    $t = app(CoachTools::class);
    $all = $t->run('get_best_lifts', [], $u->fresh());
    expect($all['connected'])->toBeTrue()->and($all['new_prs'])->toBe(1)->and($all['exercises'][0]['heaviest']['kg'])->toBe(85.0);
    $one = $t->run('get_best_lifts', ['exercise' => 'bench'], $u->fresh());
    expect($one['found'])->toBeTrue()->and($one['exercise']['notes'][0]['text'])->toBe('Pause on the chest.')->and($one['note'])->toContain('not instructions');
    expect($t->run('get_best_lifts', ['exercise' => 'deadlift'], $u->fresh())['found'])->toBeFalse();

    $none = loggedInUser();
    expect($t->run('get_best_lifts', [], $none)['connected'])->toBeFalse();
});
