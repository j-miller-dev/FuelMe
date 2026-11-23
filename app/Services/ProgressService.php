<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Foods\QuickFoods;
use App\Domain\Nutrition\ExpenditureFilter;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\IntakeModel;
use App\Domain\Progress\NudgePicker;
use App\Domain\Progress\TrendStats;
use App\Domain\Progress\WeekInsights;
use App\Domain\Progress\UndereatingDetector;
use App\Domain\Progress\WeeklyCheckin;
use App\Models\TargetHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Feeds the pure engine (ExpenditureFilter, WeeklyCheckin, ...) from the weight and food logs. The whole history is recomputed on every
 * read (O(days), a few milliseconds), so editing a past log or weigh-in can never leave a stale trend behind.
 */
class ProgressService
{
    public function __construct(private QuickFoods $quick, private TargetService $targets, private TrainingService $training) {}

    /** @return array<string,mixed>|null null until there is a first weigh-in */
    public function overview(User $user): ?array
    {
        $profile = $user->profile;
        $targets = TargetHistory::where('user_id', $user->id)->orderBy('effective_from')->orderBy('id')->get();
        $weights = DB::table('weight_logs')->where('user_id', $user->id)->orderBy('local_date')->pluck('kg', 'local_date')->map(fn ($k) => (float) $k)->all();
        if (! $profile || $targets->isEmpty() || ! $weights) {
            return null;
        }

        $today = LocalDay::of(now(), $profile->tz, $profile->rollover_hour);
        // A weigh-in dated after today (clock or timezone change) has no day to belong to yet: leave it out rather than build an empty series.
        $weights = array_filter($weights, fn ($_, $d) => $d <= $today, ARRAY_FILTER_USE_BOTH);
        if (! $weights) {
            return null;
        }
        $first = array_key_first($weights);
        $sums = DB::table('food_logs')->where('user_id', $user->id)->where('local_date', '>=', $first)->groupBy('local_date')
            ->selectRaw('local_date, sum(kcal) as kcal, sum(case when is_rough then kcal else 0 end) as rough')->get()->keyBy('local_date');
        $estimates = DB::table('day_estimates')->where('user_id', $user->id)->where('local_date', '>=', $first)->pluck('kcal', 'local_date')->map(fn ($k) => (float) $k)->all();

        // Your own adjustments count here: "did I eat enough" is measured against what you are aiming for, not the bare recommendation.
        $offset = (int) $profile->kcal_offset;
        $proteinOverride = $profile->protein_g_override ? (float) $profile->protein_g_override : null;
        $targetFor = function (string $date) use ($targets, $offset, $proteinOverride): array {
            $t = $targets->last(fn ($x) => $x->effective_from->toDateString() <= $date) ?? $targets->first();
            $d = $t->plan_json['plan']['days'][LocalDay::dayKey($date)] ?? null;

            return ['kcal' => (float) ($d['kcal'] ?? $t->kcal) + $offset, 'protein' => $proteinOverride ?? (float) ($d['protein'] ?? $t->protein)];
        };

        // One row per day from the first weigh-in. Today is incomplete, so its intake is not used (the filter only needs yesterday's).
        $rows = [];
        $perDay = [];
        for ($d = $first; $d <= $today; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $tg = $targetFor($d);
            // An exact day is trusted, a rough day (a whole-day estimate, or rough meals) is used with a wider error, only a nearly empty day is missing.
            $m = IntakeModel::day((float) ($sums[$d]->kcal ?? 0.0), (float) ($sums[$d]->rough ?? 0.0), $estimates[$d] ?? null, $tg['kcal']);
            $logged = $d < $today && $m['state'] !== 'missing';
            $rows[] = ['date' => $d, 'weight' => $weights[$d] ?? null, 'intake' => $logged ? $m['kcal'] : null, 'intakeSd' => $logged ? $m['sd'] : null];
            $perDay[$d] = $tg + ['kcalLogged' => (float) $m['kcal'], 'logged' => $logged, 'state' => $logged ? $m['state'] : 'missing'];
        }

        $prior = $targets->first()->plan_json['prior'] ?? [];
        $filter = new ExpenditureFilter((float) config('fuel.kcal_per_kg'));
        $series = array_map(fn ($r) => ['date' => $r['date'], 'trend' => round($r['trend'], 2), 'tdee' => round($r['tdee']), 'tdeeSd' => round($r['tdeeSd']), 'scale' => $r['scale']],
            $filter->run($rows, reset($weights), (float) ($prior['tdee'] ?? 2500), (float) ($prior['sd'] ?? 350)));
        $last = end($series);
        if ($last === false) {
            return null;   // the first weigh-in is dated after today, so there is nothing to show yet
        }

        $slope14 = TrendStats::slope($series);
        $slopePrev = TrendStats::slope($series, 14, 10, 14);

        // Last 14 COMPLETED days, for adherence and the undereating check.
        $done = [];
        for ($i = 14; $i >= 1; $i--) {
            $d = date('Y-m-d', strtotime($today . " -{$i} days"));
            if (isset($perDay[$d])) {
                $p = $perDay[$d];
                $protein = (float) DB::table('food_logs')->where('user_id', $user->id)->where('local_date', $d)->sum('protein');
                $done[] = ['date' => $d, 'kcal' => $p['logged'] ? $p['kcalLogged'] : null, 'target' => $p['kcal'], 'protein' => $protein, 'proteinTarget' => $p['protein']];
            }
        }
        $last7 = array_slice($done, -7);
        // The weekly consistency score (instead of a streak): days that counted, however roughly, out of the last 7 finished days.
        $week = [];
        for ($i = 7; $i >= 1; $i--) {
            $d = date('Y-m-d', strtotime($today . " -{$i} days"));
            if (isset($perDay[$d])) {
                $p = $perDay[$d];
                $week[] = ['state' => $p['state'], 'kcal' => $p['logged'] ? $p['kcalLogged'] : null, 'target' => $p['kcal']];
            }
        }
        $loggedDays7 = Adherence::loggedCount($last7);
        $weighIns14 = count(array_filter(array_keys($weights), fn ($d) => $d > date('Y-m-d', strtotime($today . ' -14 days'))));

        $latest = $targets->last();
        $since = (int) round((strtotime($today) - strtotime($latest->effective_from->toDateString())) / 86400);
        $atTarget = collect($series)->firstWhere('date', $latest->effective_from->toDateString());
        $goal = $user->goal;

        $under = UndereatingDetector::detect($done);
        $insights = $this->insights($user, $last7, (float) ($latest->plan_json['plan']['weekly']['proteinPerMealG'] ?? 30), $done, $weighIns14);
        $checkin = $slope14 !== null || count($series) < WeeklyCheckin::MIN_DAYS_OF_DATA ? WeeklyCheckin::evaluate([
            'trendKg' => $last['trend'], 'tdee' => (float) $last['tdee'], 'tdeeSd' => (float) $last['tdeeSd'], 'phase' => $goal->phase, 'goalPctPerWeek' => (float) $goal->rate_pct_per_week,
            'previousTarget' => (float) $latest->kcal, 'tdeeAtLastTarget' => $atTarget ? (float) $atTarget['tdee'] : null, 'slope14' => $slope14, 'slopePrev14' => $slopePrev,
            'weighIns14' => $weighIns14, 'loggedDays7' => $loggedDays7, 'daysOfData' => count($series), 'daysSinceChange' => $since, 'undereating' => $under['flag'], 'kcalPerKg' => (float) config('fuel.kcal_per_kg'),
        ]) : null;

        $adds = [];
        if ($under['flag']) {
            $adds = NudgePicker::pick($this->usualPortions($user), $under['gapKcal'], $under['gapProtein']);
        }

        return [
            'series' => $series, 'today' => $today,
            'summary' => [
                'trendKg' => $last['trend'], 'tdee' => $last['tdee'], 'tdeeSd' => $last['tdeeSd'], 'calibrating' => $last['tdeeSd'] >= WeeklyCheckin::CALIBRATED_SD,
                'ratePctPerWeek' => $slope14['pctPerWeek'] ?? null, 'rateKgPerWeek' => $slope14['kgPerWeek'] ?? null, 'goalPctPerWeek' => (float) $goal->rate_pct_per_week,
                'phase' => $goal->phase, 'target' => (int) $latest->kcal,
            ],
            'adherence' => ['loggedDays7' => $loggedDays7, 'weighIns14' => $weighIns14],
            'consistency' => IntakeModel::consistency($week),
            'checkin' => $checkin, 'undereating' => $under + ['adds' => $adds], 'insights' => $insights,
            'weights' => DB::table('weight_logs')->where('user_id', $user->id)->orderByDesc('local_date')->limit(14)->get(['id', 'local_date', 'kg'])
                ->map(fn ($w) => ['id' => (int) $w->id, 'date' => (string) $w->local_date, 'kg' => (float) $w->kg])->all(),
        ];
    }

    /**
     * @param list<array{date:string,kcal:?float,target:float,protein:float,proteinTarget:float}> $last7
     * @param list<array{date:string,kcal:?float}> $done14
     */
    private function insights(User $user, array $last7, float $perMeal, array $done14, int $weighIns14): array
    {
        $dates = array_column($last7, 'date');
        $rows = $dates ? DB::table('food_logs')->where('user_id', $user->id)->whereIn('local_date', $dates)->get(['local_date', 'meal_slot', 'kcal', 'protein', 'fibre']) : collect();
        $days = array_map(function ($d) use ($rows) {
            $r = $rows->where('local_date', $d['date']);

            return $d + ['fibre' => (float) $r->sum('fibre'), 'kcalWithFibre' => (float) $r->whereNotNull('fibre')->sum('kcal'), 'kcalLogged' => (float) $r->sum('kcal')];
        }, $last7);
        $slots = [];
        foreach ($rows->groupBy('meal_slot') as $slot => $g) {
            $slots[$slot] = ['days' => $g->pluck('local_date')->unique()->count(), 'protein' => (float) $g->sum('protein')];
        }

        return WeekInsights::compute($days, $slots, $perMeal, Adherence::loggedCount($done14), $weighIns14);
    }

    /** Lightweight: only whether the estimate has settled (for the Today badge). */
    public function calibrating(User $user): bool
    {
        return (bool) (($this->overview($user)['summary']['calibrating'] ?? true));
    }

    /**
     * Apply the proposed target. The server recomputes it and refuses if it no longer matches what the person saw, so a stale screen or a
     * double tap can never apply a number nobody looked at. After applying, the next check-in is a week away.
     *
     * @return array{applied:bool, kcal:int}
     */
    public function applyCheckin(User $user, int $expectedKcal): array
    {
        $o = $this->overview($user);
        $c = $o['checkin'] ?? null;
        abort_unless($c && $c['status'] === 'ready' && $c['change'] !== 0, 422, 'There is no change to apply right now.');
        abort_if($c['proposedKcal'] !== $expectedKcal, 409, 'The check-in changed since you opened it. Reload to see the new numbers.');

        $profile = $user->profile;
        $latest = TargetHistory::where('user_id', $user->id)->latest('effective_from')->latest('id')->firstOrFail();
        $plan = $this->targets->replan([
            'sex' => $profile->sex, 'body_fat_pct' => $profile->body_fat_pct, 'lifting_style' => $profile->lifting_style, 'phase' => $user->goal->phase,
            'protein_g_per_kg' => $profile->protein_g_per_kg_override, 'carb_mode' => $profile->carb_mode,
        ], $o['summary']['trendKg'], (float) $c['proposedKcal'], $this->training->templateWeek($user));  // the recommendation: your overrides are layered on at read time

        $w = $plan['weekly'];
        TargetHistory::create([
            'user_id' => $user->id, 'effective_from' => $o['today'], 'kcal' => (int) $w['kcalAvg'], 'protein' => (int) $w['protein'], 'fat' => (int) $w['fat'], 'carbs' => (int) $w['carbsAvg'],
            'reason' => 'weekly_checkin',
            'plan_json' => array_merge($latest->plan_json, ['plan' => ['days' => $plan['days'], 'weekly' => $plan['weekly']], 'kcalTarget' => (int) $w['kcalAvg'], 'warnings' => $plan['warnings'],
                'why' => $c['lines'], 'inputs' => array_merge($latest->plan_json['inputs'] ?? [], ['weightKg' => $o['summary']['trendKg']]),
                'checkin' => ['date' => $o['today'], 'from' => (int) $latest->kcal, 'to' => $c['proposedKcal'], 'guard' => $c['guard']]]),
        ]);

        return ['applied' => true, 'kcal' => (int) $w['kcalAvg']];
    }

    /** @return list<array{name:string,grams:float,unit:string,kcal:float,protein:float}> one usual portion of each of your usual foods */
    private function usualPortions(User $user): array
    {
        $tz = $user->profile->tz;
        $q = $this->quick->for($user->id, now(), $tz);
        $out = [];
        foreach (array_merge($q['frequent'], $q['recents'], $q['favourites']) as $h) {
            $g = (float) ($h['last_grams'] ?? ($h['servings'][0]['grams'] ?? 100));
            $per = $h['per100'];
            if ($per['kcal'] === null) {
                continue;
            }
            $out[$h['id']] = ['name' => $h['brand'] && ! str_contains(mb_strtolower($h['name']), mb_strtolower($h['brand'])) ? $h['brand'] . ' ' . $h['name'] : $h['name'],
                'grams' => $g, 'unit' => $h['is_liquid'] ? 'mL' : 'g', 'kcal' => round($per['kcal'] * $g / 100), 'protein' => round(($per['protein'] ?? 0) * $g / 100, 1)];
        }

        return array_values($out);
    }
}
