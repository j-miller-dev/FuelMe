<?php

namespace App\Services\Hevy;

use App\Domain\Progress\Adherence;
use App\Domain\Progress\Insights;
use App\Domain\Training\EffortRamp;
use App\Domain\Training\Strength;
use App\Models\TargetHistory;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Support\Facades\DB;

/** Your lifting numbers from synced Hevy workouts. Estimates and rules of thumb, labelled as such; nothing here changes a target. */
class StrengthService
{
    public function __construct(private TrainingService $training, private \App\Services\DeloadService $deloads) {}

    public function connected(User $user): bool
    {
        return DB::table('hevy_connections')->where('user_id', $user->id)->exists();
    }

    /** @return list<array<string,mixed>> sessions in Strength's shape, oldest first */
    public function sessions(User $user, int $days = 180): array
    {
        $from = date('Y-m-d', strtotime(now($user->profile->tz)->toDateString() . " -{$days} days"));
        $w = DB::table('hevy_workouts')->where('user_id', $user->id)->where('local_date', '>=', $from)->orderBy('local_date')->orderBy('start_at')->get();
        if ($w->isEmpty()) {
            return [];
        }
        $deloadSet = array_flip($this->deloads->mondays($user));
        $tpl = DB::table('hevy_templates')->where('user_id', $user->id)->get()->keyBy('template_id');
        $sets = DB::table('hevy_sets')->whereIn('hevy_workout_id', $w->pluck('id'))->orderBy('exercise_index')->orderBy('set_index')->get()->groupBy('hevy_workout_id');
        $out = [];
        foreach ($w as $row) {
            $ex = [];
            foreach (($sets[$row->id] ?? collect())->groupBy('exercise_index') as $list) {
                $first = $list->first();
                $t = $tpl[$first->template_id] ?? null;
                $ex[] = ['id' => $first->template_id ?: $first->exercise_title, 'title' => $first->exercise_title, 'muscle' => $t->muscle ?? null, 'secondary' => $t ? (json_decode($t->secondary, true) ?: []) : [],
                    'sets' => $list->map(fn ($s) => ['type' => $s->type, 'kg' => $s->weight_kg !== null ? (float) $s->weight_kg : null, 'reps' => $s->reps !== null ? (int) $s->reps : null, 'rpe' => $s->rpe !== null ? (float) $s->rpe : null])->all()];
            }
            $out[] = ['date' => (string) $row->local_date, 'title' => $row->title, 'minutes' => $row->minutes !== null ? (int) $row->minutes : null, 'exercises' => $ex, 'deload' => isset($deloadSet[\App\Domain\Training\Deload::mondayOf((string) $row->local_date)])];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function overview(User $user, int $days = 90): array
    {
        $all = $this->sessions($user, 180);
        $train = array_values(array_filter($all, fn ($s) => ! $s['deload']));   // deload weeks are left out of stall and best-set checks: an easy week is not a problem
        $today = now($user->profile->tz)->toDateString();
        $window = array_values(array_filter($train, fn ($s) => $s['date'] >= date('Y-m-d', strtotime($today . " -{$days} days"))));
        $step = (float) config('fuel.hevy.plate_step_kg');

        $exercises = [];
        $ids = [];
        foreach (array_reverse($window) as $s) {
            foreach ($s['exercises'] as $ex) {
                $ids[$ex['id']] ??= $ex;
            }
        }
        foreach ($ids as $id => $ex) {
            $h = Strength::history($window, (string) $id);
            if (! $h) {
                continue;
            }
            $st = Strength::status($h);
            $last = $h[count($h) - 1];
            $exercises[] = ['id' => (string) $id, 'title' => $ex['title'], 'muscle' => $ex['muscle'], 'status' => $st['status'], 'sessions' => $st['sessions'], 'best_e1rm' => $st['best'], 'latest_e1rm' => $st['latest'],
                'last_date' => $last['date'], 'last_set' => ['kg' => $last['kg'], 'reps' => $last['reps']], 'trend' => array_map(fn ($x) => $x['e1rm'], array_slice($h, -8)), 'beat_the_log' => Strength::beatTheLog($last, $step)];
        }
        usort($exercises, fn ($a, $b) => strcmp($b['last_date'], $a['last_date']) ?: strcmp($a['title'], $b['title']));

        $ramp = $user->profile->effort_ramp ?: null;
        $monday = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $lastMonday = date('Y-m-d', strtotime($monday . ' -7 days'));
        $recent = [];
        foreach (array_slice(array_reverse($all), 0, 8) as $s) {
            $idx = $s['deload'] ? ['index' => null, 'beat' => 0, 'matched' => 0, 'down' => 0, 'compared' => 0] : Strength::sessionIndex($train, $s['date']);
            $all_sets = [];
            foreach ($s['exercises'] as $ex) {
                array_push($all_sets, ...$ex['sets']);
            }
            $eff = Strength::effort($all_sets);
            $wk = EffortRamp::weekFor($ramp, $s['date']);
            $vs = $wk ? EffortRamp::compare(Strength::hardestRirs($s['exercises']), $wk['min'], $wk['max']) + ['planned' => $wk['label']] : null;
            $recent[] = ['date' => $s['date'], 'title' => $s['title'], 'minutes' => $s['minutes'], 'exercises' => count($s['exercises']), 'working_sets' => $eff['working'], 'effort' => $eff, 'vs_plan' => $s['deload'] ? null : $vs, 'vs_your_best' => $idx, 'deload' => $s['deload']];
        }

        return [
            'deload_now' => $this->deloads->isDeload($user, $today),
            'effort_plan' => ['this_week' => EffortRamp::weekFor($ramp, $today), 'cycle' => $ramp, 'note' => 'Set by you. The cycle repeats after its last week. Only sets where you logged effort (failure tag or RPE) are compared.'],
            'sessions_in_window' => count($window), 'exercises' => $exercises, 'recent' => $recent,
            'weekly_sets' => ['this_week' => Strength::weeklySets($all, $monday, $today), 'last_week' => Strength::weeklySets($all, $lastMonday, date('Y-m-d', strtotime($monday . ' -1 day')))],
            'strength_vs_bodyweight' => $this->vsBodyweight($user, $window),
            'assumptions' => ['e1RM is an estimate (Epley, 1-12 reps only); a set tagged failure counts as 0 reps in reserve, and with an RPE logged the reps in reserve are added (RPE 9 = 1). Sets with neither are not adjusted', 'weekly sets: a secondary muscle counts half (rule of thumb)', 'status needs at least 3 sessions, a stall at least 5 over 2+ weeks; sessions in a week you labelled a deload are left out of status and best-set checks', 'load step for "beat the log" is ' . $step . ' kg (an assumption)'],
        ];
    }

    /** @param list<array<string,mixed>> $window @return array<string,mixed> */
    private function vsBodyweight(User $user, array $window): array
    {
        $sc = Strength::strengthChange($window);
        if ($window === []) {
            return ['strength_change_pct' => null, 'weight_change_kg' => null, 'exercises' => 0, 'note' => 'No lifting data yet.'];
        }
        $w = DB::table('weight_logs')->where('user_id', $user->id)->where('local_date', '>=', $window[0]['date'])->orderBy('local_date')->pluck('kg')->map(fn ($k) => (float) $k)->all();
        $dw = count($w) >= 6 ? round(array_sum(array_slice($w, -3)) / 3 - array_sum(array_slice($w, 0, 3)) / 3, 1) : null;

        return ['strength_change_pct' => $sc['change_pct'], 'weight_change_kg' => $dw, 'exercises' => $sc['exercises'],
            'note' => 'Strength going up while weight goes up is a good sign for a bulk, but it is a rough proxy. It does not measure muscle.'];
    }

    /** Session performance (vs your own earlier best) against yesterday's carbs per kg and this morning's sleep. Needs >= 8 comparable sessions each. @return list<array<string,mixed>> */
    public function insights(User $user): array
    {
        $all = array_values(array_filter($this->sessions($user, 365), fn ($s) => ! $s['deload']));
        $perf = [];
        foreach ($all as $s) {
            $i = Strength::sessionIndex($all, $s['date']);
            if ($i['index'] !== null && $i['compared'] >= 2) {
                $perf[$s['date']] = $i['index'];
            }
        }
        if (count($perf) < Insights::MIN_PAIRS) {
            return [];
        }
        $kg = max(1.0, $this->training->kg($user));
        $targets = TargetHistory::where('user_id', $user->id)->orderBy('effective_from')->orderBy('id')->get();
        $food = DB::table('food_logs')->where('user_id', $user->id)->where('is_rough', false)->groupBy('local_date')->selectRaw('local_date, sum(kcal) as kcal, sum(carbs) as carbs')->get()->keyBy(fn ($r) => (string) $r->local_date);
        $sleep = DB::table('readiness_logs')->where('user_id', $user->id)->pluck('sleep', 'local_date');
        $watchDays = DB::table('watch_days')->where('user_id', $user->id)->whereIn('local_date', array_keys($perf))->get()->keyBy(fn ($r) => (string) $r->local_date);
        $carbPairs = $sleepPairs = $watchSleep = $hrvPairs = [];
        foreach ($perf as $date => $idx) {
            $prev = date('Y-m-d', strtotime($date . ' -1 day'));
            $f = $food[$prev] ?? null;
            $t = $targets->last(fn ($x) => $x->effective_from->toDateString() <= $prev);
            if ($f && $t && (float) $f->kcal >= Adherence::LOGGED_AT * (float) $t->kcal) {
                $carbPairs[] = [(float) $f->carbs / $kg, $idx];
            }
            if (isset($sleep[$date])) {
                $sleepPairs[] = [(float) $sleep[$date], $idx];
            }
            $wd = $watchDays[$date] ?? null;
            if ($wd && $wd->sleep_secs !== null) {
                $watchSleep[] = [(float) $wd->sleep_secs / 3600, $idx];
            }
            if ($wd && $wd->hrv !== null) {
                $hrvPairs[] = [(float) $wd->hrv, $idx];
            }
        }

        return array_values(array_filter([
            Insights::pair('carbs_lifting', 'Carbs the day before and lifting', 'carbs the day before (g per kg)', 'lifting performance versus your own best', $carbPairs),
            Insights::pair('sleep_lifting', 'Sleep and lifting', 'sleep quality that morning', 'lifting performance versus your own best', $sleepPairs),
            Insights::pair('watch_sleep_lifting', 'Watch sleep and lifting', 'hours slept the night before (from your watch)', 'lifting performance versus your own best', $watchSleep),
            Insights::pair('hrv_lifting', 'HRV and lifting', 'HRV that morning (from your watch)', 'lifting performance versus your own best', $hrvPairs),
        ]));
    }
}
