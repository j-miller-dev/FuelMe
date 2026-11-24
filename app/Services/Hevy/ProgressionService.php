<?php

namespace App\Services\Hevy;

use App\Domain\Training\EffortRamp;
use App\Domain\Training\Progression;
use App\Domain\Training\Strength;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suggested weights and projections for your routines, from your own synced sets (see Domain\Training\Progression for the rules).
 * Suggestions only: FUEL changes nothing in Hevy and nothing here is a prescription. Deload weeks are left out of every calculation.
 */
class ProgressionService
{
    public function __construct(private StrengthService $strength) {}

    /** Loads everything once so a page with many exercises does one pass over your history. @return array<string,mixed> */
    public function context(User $user): array
    {
        $today = now($user->profile->tz)->toDateString();
        $all = $this->strength->sessions($user, 3650);
        $w = EffortRamp::weekFor($user->profile->effort_ramp ?: null, $today);

        return [
            'today' => $today, 'all' => $all, 'train' => array_values(array_filter($all, fn ($s) => ! $s['deload'])),
            'effort' => $w ? ['min' => (int) $w['min'], 'max' => (int) $w['max']] : null, 'effort_label' => $w['label'] ?? null,
            'prefs' => DB::table('exercise_prefs')->where('user_id', $user->id)->pluck('step_kg', 'template_id')->map(fn ($v) => (float) $v)->all(),
        ];
    }

    /** @param array<string,mixed> $ctx */
    public function step(array $ctx, string $tid, string $title): array
    {
        $custom = isset($ctx['prefs'][$tid]);

        return ['kg' => $custom ? $ctx['prefs'][$tid] : Progression::defaultStep($title, (array) config('fuel.hevy.steps_kg'), (float) config('fuel.hevy.plate_step_kg')), 'custom' => $custom];
    }

    /**
     * @param array<string,mixed> $ctx from context()
     * @param list<array<string,mixed>>|null $plan the routine's planned sets for this exercise
     * @return array{next:array<string,mixed>,projection:array<string,mixed>,step:array{kg:float,custom:bool}}
     */
    public function forExercise(array $ctx, string $tid, string $title, ?array $plan): array
    {
        $step = $this->step($ctx, $tid, $title);
        $sessions = [];
        if ($tid !== '') {
            foreach ($ctx['train'] as $s) {
                $sets = [];
                foreach ($s['exercises'] as $x) {
                    if ((string) $x['id'] === $tid) {
                        array_push($sets, ...$x['sets']);
                    }
                }
                if ($sets) {
                    $sessions[] = ['date' => $s['date'], 'sets' => $sets];
                }
            }
        }
        $hist = $tid !== '' ? Strength::history($ctx['train'], $tid) : [];
        $status = $hist ? Strength::status($hist)['status'] : null;
        [, $hi] = Progression::range($plan);

        return [
            'next' => Progression::recommend($sessions, $plan, $step['kg'], $ctx['effort'], $ctx['today'], $status),
            'projection' => Progression::projection(array_map(fn ($h) => ['date' => $h['date'], 'e1rm' => $h['e1rm']], $hist), $hi, $step['kg'], $ctx['effort']),
            'step' => $step,
        ];
    }

    /** Folders of routines, and the suggestions for every exercise in one folder (default: "Rational Intensity", else the folder you did most recently). @return array<string,mixed> */
    public function program(User $user, ?string $folderKey): array
    {
        $routines = DB::table('hevy_routines')->where('user_id', $user->id)->get();
        $folders = [];
        foreach ($routines as $r) {
            $k = $r->folder_id ?? 'none';
            $folders[$k] ??= ['key' => $k, 'title' => $r->folder_title ?: 'No folder', 'routines' => 0];
            $folders[$k]['routines']++;
        }
        uasort($folders, fn ($a, $b) => [$a['key'] === 'none', strtolower($a['title'])] <=> [$b['key'] === 'none', strtolower($b['title'])]);
        $folders = array_values($folders);
        $conn = DB::table('hevy_connections')->where('user_id', $user->id)->exists();
        if (! $folders) {
            return ['connected' => $conn, 'folders' => [], 'selected' => null, 'routines' => [], 'effort_now' => null, 'summary' => null, 'assumptions' => $this->assumptions()];
        }

        $keys = array_column($folders, 'key');
        $sel = $folderKey !== null && in_array($folderKey, $keys, true) ? $folderKey : null;
        if ($sel === null) {
            foreach ($folders as $f) {
                if (preg_match('/rational\s+intensity/i', $f['title'])) {
                    $sel = $f['key'];
                    break;
                }
            }
        }
        $sel ??= $this->mostRecentFolder($user, $routines) ?? $folders[0]['key'];

        $ctx = $this->context($user);
        $tpl = DB::table('hevy_templates')->where('user_id', $user->id)->pluck('muscle', 'template_id');
        $out = [];
        $count = ['increase' => 0, 'hold' => 0, 'repeat' => 0, 'reduce' => 0, 'start' => 0, 'no_load' => 0];
        foreach ($routines->filter(fn ($r) => ($r->folder_id ?? 'none') === $sel)->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE) as $r) {
            $exs = [];
            foreach (DB::table('hevy_routine_exercises')->where('hevy_routine_id', $r->id)->orderBy('exercise_index')->get() as $e) {
                $p = $this->forExercise($ctx, (string) $e->template_id, $e->title, json_decode($e->sets, true) ?: []);
                $count[$p['next']['action']] = ($count[$p['next']['action']] ?? 0) + 1;
                $exs[] = ['template_id' => (string) $e->template_id, 'title' => $e->title, 'muscle' => $tpl[$e->template_id] ?? null] + $p;
            }
            $out[] = ['id' => (int) $r->id, 'title' => $r->title, 'exercises' => $exs];
        }

        return ['connected' => true, 'folders' => $folders, 'selected' => $sel, 'routines' => $out, 'effort_now' => $ctx['effort'] ? ['min' => $ctx['effort']['min'], 'max' => $ctx['effort']['max'], 'label' => $ctx['effort_label']] : null, 'summary' => $count, 'assumptions' => $this->assumptions()];
    }

    /** @return list<string> */
    public function assumptions(): array
    {
        return [
            'Suggestions come from simple visible rules (double progression) on your own logged sets. They are not a prescription and not the rules of any named program: you decide.',
            'A weight goes up when every set at your top weight reached the top of the routine\'s rep range. Rep ranges come from Hevy; with no range FUEL only suggests adding a rep.',
            'Estimated 1RM is an estimate (Epley, 1 to 12 reps). Projections extrapolate your own trend as a range, capped at 4% per 4 weeks. Real gains slow down.',
            'Smallest jumps are guessed from the exercise name (dumbbell ' . config('fuel.hevy.steps_kg.dumbbell') . ' kg, others ' . config('fuel.hevy.plate_step_kg') . ' kg). You can set your own per exercise.',
            'Deload weeks you labelled are left out. Sets without an RPE or failure tag are never treated as hard or easy.',
        ];
    }

    private function mostRecentFolder(User $user, $routines): ?string
    {
        $workouts = DB::table('hevy_workouts')->where('user_id', $user->id)->orderByDesc('local_date')->limit(60)->get(['routine_id', 'title']);
        foreach ($workouts as $w) {
            foreach ($routines as $r) {
                if (($w->routine_id !== null && $w->routine_id === $r->hevy_id) || ($w->routine_id === null && mb_strtolower($w->title) === mb_strtolower($r->title))) {
                    return $r->folder_id ?? 'none';
                }
            }
        }

        return null;
    }
}
