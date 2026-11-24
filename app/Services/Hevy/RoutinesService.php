<?php

namespace App\Services\Hevy;

use App\Domain\Training\EffortRamp;
use App\Domain\Training\PersonalRecords;
use App\Domain\Training\Strength;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Your Hevy routines (read-only copies) next to your own numbers: what the routine plans, what you did last time, what to beat, and your notes.
 * FUEL never edits the routine in Hevy and never decides what you should lift: "beat the log" is a prompt from your own last set.
 */
class RoutinesService
{
    public function __construct(private StrengthService $strength, private \App\Services\DeloadService $deloads) {}

    /** @return array<string,mixed> */
    public function list(User $user): array
    {
        $conn = DB::table('hevy_connections')->where('user_id', $user->id)->first();
        // sorted here, not in SQL: databases disagree on where NULL (no folder) sorts. Folders A to Z, no folder last, then by title.
        $rows = DB::table('hevy_routines')->where('user_id', $user->id)->get()->sort(fn ($a, $b) => [$a->folder_title === null, mb_strtolower((string) $a->folder_title), mb_strtolower($a->title)] <=> [$b->folder_title === null, mb_strtolower((string) $b->folder_title), mb_strtolower($b->title)])->values();
        $ex = DB::table('hevy_routine_exercises')->whereIn('hevy_routine_id', $rows->pluck('id'))->get()->groupBy('hevy_routine_id');
        $tpl = DB::table('hevy_templates')->where('user_id', $user->id)->pluck('muscle', 'template_id');
        $done = DB::table('hevy_workouts')->where('user_id', $user->id)->orderBy('local_date')->get(['routine_id', 'title', 'local_date']);
        $out = [];
        foreach ($rows as $r) {
            $list = $ex[$r->id] ?? collect();
            $sets = 0;
            $muscles = [];
            foreach ($list as $e) {
                $sets += count(array_filter(json_decode($e->sets, true) ?: [], fn ($s) => ($s['type'] ?? 'normal') !== 'warmup'));
                if (! empty($tpl[$e->template_id])) {
                    $muscles[$tpl[$e->template_id]] = ($muscles[$tpl[$e->template_id]] ?? 0) + 1;
                }
            }
            arsort($muscles);
            $out[] = ['id' => (int) $r->id, 'title' => $r->title, 'folder' => $r->folder_title, 'exercises' => $list->count(), 'working_sets' => $sets, 'muscles' => array_slice(array_keys($muscles), 0, 3), 'last_done' => $this->lastDone($done, $r)];
        }

        return ['routines' => $out, 'synced_at' => $conn?->routines_synced_at ? \Illuminate\Support\Carbon::parse($conn->routines_synced_at, 'UTC')->toIso8601String() : null, 'error' => $conn?->routines_error];
    }

    /** @return array<string,mixed>|null */
    public function show(User $user, int $id): ?array
    {
        $r = DB::table('hevy_routines')->where('user_id', $user->id)->where('id', $id)->first();
        if (! $r) {
            return null;
        }
        $today = now($user->profile->tz)->toDateString();
        $all = $this->strength->sessions($user, 3650);
        $train = array_values(array_filter($all, fn ($s) => ! $s['deload']));
        $records = [];
        foreach (PersonalRecords::build($all, $today) as $p) {
            $records[$p['id']] = $p;
        }
        $tpl = DB::table('hevy_templates')->where('user_id', $user->id)->get()->keyBy('template_id');
        $notes = DB::table('exercise_notes')->where('user_id', $user->id)->orderByDesc('local_date')->orderByDesc('id')->get()->groupBy('template_id');
        $step = (float) config('fuel.hevy.plate_step_kg');
        $done = DB::table('hevy_workouts')->where('user_id', $user->id)->orderBy('local_date')->get(['routine_id', 'title', 'local_date']);

        $exs = [];
        foreach (DB::table('hevy_routine_exercises')->where('hevy_routine_id', $r->id)->orderBy('exercise_index')->get() as $e) {
            $tid = (string) $e->template_id;
            $h = $tid !== '' ? Strength::history($train, $tid) : [];
            $lastBest = $h ? $h[count($h) - 1] : null;
            $lastSets = [];
            $lastDate = null;
            if ($tid !== '') {
                foreach (array_reverse($all) as $s) {
                    foreach ($s['exercises'] as $x) {
                        if ((string) $x['id'] === $tid) {
                            $lastDate = $s['date'];
                            $lastSets = array_map(fn ($z) => ['type' => $z['type'] ?? 'normal', 'kg' => $z['kg'], 'reps' => $z['reps'], 'rpe' => $z['rpe']], $x['sets']);
                            break 2;
                        }
                    }
                }
            }
            $st = $h ? Strength::status($h) : null;
            $rec = $records[$tid] ?? null;
            $mine = ($notes[$tid] ?? collect())->take(3)->map(fn ($n) => ['id' => (int) $n->id, 'kind' => $n->kind, 'body' => $n->body, 'date' => (string) $n->local_date])->values()->all();
            $exs[] = [
                'index' => (int) $e->exercise_index, 'template_id' => $tid, 'title' => $e->title, 'muscle' => $tpl[$tid]->muscle ?? null, 'rest_seconds' => $e->rest_seconds !== null ? (int) $e->rest_seconds : null,
                'hevy_note' => $e->notes, 'superset' => $e->superset_id, 'planned' => json_decode($e->sets, true) ?: [],
                'last' => $lastDate ? ['date' => $lastDate, 'sets' => $lastSets] : null,
                'status' => $st ? $st['status'] : null,
                'beat_the_log' => $lastBest ? Strength::beatTheLog($lastBest, $step) : [],
                'heaviest' => $rec['heaviest'] ?? null, 'best_e1rm' => $rec['best_e1rm'] ?? null, 'has_new' => (bool) ($rec['has_new'] ?? false),
                'notes' => $mine, 'linkable' => $tid !== '' && (isset($tpl[$tid]) || $h !== [] || $mine !== []),
            ];
        }

        $ramp = $user->profile->effort_ramp ?: null;

        return ['id' => (int) $r->id, 'title' => $r->title, 'folder' => $r->folder_title, 'notes' => $r->notes, 'last_done' => $this->lastDone($done, $r), 'exercises' => $exs,
            'effort_now' => EffortRamp::weekFor($ramp, $today), 'deload_now' => $this->deloads->isDeload($user, $today),
            'notes_about' => ['Planned sets are read from Hevy and are not changed here.', '"Last time" is your most recent logged session of that exercise, from any routine. Beat the log is a prompt from your own last best set (+1 rep, or +' . $step . ' kg, an assumption), not a prescription.']];
    }

    /** The most recent date this routine was done: by the routine id Hevy gives, else a workout with the same title. */
    private function lastDone($workouts, object $r): ?string
    {
        $d = null;
        foreach ($workouts as $w) {
            if (($w->routine_id !== null && $w->routine_id === $r->hevy_id) || ($w->routine_id === null && mb_strtolower($w->title) === mb_strtolower($r->title))) {
                $d = (string) $w->local_date;   // ordered oldest first, so the last match wins
            }
        }

        return $d;
    }
}
