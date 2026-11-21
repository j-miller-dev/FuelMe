<?php

namespace App\Services\Coach;

use App\Domain\Foods\LocalDay;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\IntakeModel;
use App\Domain\Progress\WeeklyCheckin;
use App\Models\User;
use App\Services\FuelService;
use App\Services\PlanService;
use App\Services\ProgressService;
use App\Services\TrainingService;
use Illuminate\Support\Facades\DB;

/**
 * The coach's tools. All READ-ONLY: each one wraps a service the app already uses and returns a small, rounded array. There is no tool that
 * writes, changes a target, or logs anything, and no tool that dumps raw food history. Every number the coach may say comes from here.
 */
class CoachTools
{
    public function __construct(private ProgressService $progress, private PlanService $plans, private TrainingService $training, private FuelService $fuel) {}

    /** @return list<array<string,mixed>> Anthropic tool definitions */
    public function definitions(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass()];
        $days = fn (string $d) => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 180, 'description' => $d]]];

        return [
            ['name' => 'get_today_status', 'description' => 'Today: target, eaten so far, what is left, sessions and water.', 'input_schema' => $none],
            ['name' => 'get_targets', 'description' => 'Daily targets for one date (default today): your numbers next to the app recommendation, the day type and why it changed.', 'input_schema' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD']]]],
            ['name' => 'get_week_plan', 'description' => 'The usual week: each day type and its targets.', 'input_schema' => $none],
            ['name' => 'get_trend', 'description' => 'Trend weight over the last N days: start, end, change and rate per week.', 'input_schema' => $days('how many days back (default 28)')],
            ['name' => 'get_expenditure', 'description' => 'Estimated daily expenditure (TDEE), how sure it is, and how it moved over N days.', 'input_schema' => $days('how many days back (default 28)')],
            ['name' => 'get_weekly_checkin', 'description' => 'The current weekly check-in: status, proposed target, and the engine\'s own reason lines.', 'input_schema' => $none],
            ['name' => 'get_training', 'description' => 'Sessions in the last N days (type, intensity, minutes, status, energy estimate).', 'input_schema' => $days('how many days back (default 14)')],
            ['name' => 'get_adherence', 'description' => 'How many of the last days counted, weigh-ins, and whether you have been under target.', 'input_schema' => $none],
            ['name' => 'get_next_session_fuel', 'description' => 'The next session (today, else tomorrow): when, and the eating and fluid plan around it.', 'input_schema' => $none],
            ['name' => 'get_assumptions', 'description' => 'The fixed assumptions and rules of thumb the app uses, so you can say what is an estimate.', 'input_schema' => $none],
        ];
    }

    /** @return array<string,mixed> */
    public function run(string $name, array $args, User $user): array
    {
        return match ($name) {
            'get_today_status' => $this->today($user),
            'get_targets' => $this->targets($user, (string) ($args['date'] ?? '')),
            'get_week_plan' => $this->week($user),
            'get_trend' => $this->trend($user, $this->days($args, 28)),
            'get_expenditure' => $this->expenditure($user, $this->days($args, 28)),
            'get_weekly_checkin' => $this->checkin($user),
            'get_training' => $this->trainingRange($user, $this->days($args, 14)),
            'get_adherence' => $this->adherence($user),
            'get_next_session_fuel' => $this->nextFuel($user),
            'get_assumptions' => $this->assumptions(),
            default => ['error' => 'Unknown tool.'],
        };
    }

    private function days(array $a, int $default): int
    {
        return max(1, min(180, (int) ($a['days'] ?? $default)));
    }

    private function todayDate(User $u): string
    {
        return LocalDay::of(now(), $u->profile->tz, $u->profile->rollover_hour);
    }

    private function day(array $d): array
    {
        return ['kcal' => (int) round($d['kcal']), 'protein_g' => (int) round($d['protein']), 'carbs_g' => (int) round($d['carbs']), 'fat_g' => (int) round($d['fat']), 'day_type' => $d['class'] ?? null];
    }

    private function today(User $u): array
    {
        $date = $this->todayDate($u);
        $d = $this->plans->forDay($u, $date);
        $eaten = DB::table('food_logs')->where('user_id', $u->id)->where('local_date', $date)->selectRaw('coalesce(sum(kcal),0) k, coalesce(sum(protein),0) p, coalesce(sum(carbs),0) c, coalesce(sum(fat),0) f')->first();
        $t = $this->day($d['day']);
        $e = ['kcal' => (int) round($eaten->k), 'protein_g' => (int) round($eaten->p), 'carbs_g' => (int) round($eaten->c), 'fat_g' => (int) round($eaten->f)];

        return ['date' => $date, 'target' => $t, 'eaten' => $e,
            'left' => ['kcal' => $t['kcal'] - $e['kcal'], 'protein_g' => $t['protein_g'] - $e['protein_g'], 'carbs_g' => $t['carbs_g'] - $e['carbs_g'], 'fat_g' => $t['fat_g'] - $e['fat_g']],
            'why_today_differs' => $d['explain'],
            'sessions' => array_map(fn ($s) => ['label' => $s['label'], 'start' => $s['start'], 'minutes' => $s['minutes'], 'status' => $s['status']], $this->training->sessions($u, $date)),
            'water_ml' => (int) DB::table('water_logs')->where('user_id', $u->id)->where('local_date', $date)->sum('ml')];
    }

    private function targets(User $u, string $date): array
    {
        $today = $this->todayDate($u);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= date('Y-m-d', strtotime($today . ' -60 days')) && $date <= date('Y-m-d', strtotime($today . ' +14 days')) ? $date : $today;
        $d = $this->plans->forDay($u, $date);
        $rec = $this->plans->base($u, false)['days'][LocalDay::dayKey($date)] ?? null;

        return ['date' => $date, 'yours' => $this->day($d['day']), 'app_recommendation' => $rec ? $this->day($rec) : null, 'kcal_adjustment' => (int) $u->profile->kcal_offset,
            'protein_override_g' => $u->profile->protein_g_override ? (int) $u->profile->protein_g_override : null, 'why_it_differs_from_usual_week' => $d['explain'], 'warnings' => array_map(fn ($w) => $w['msg'] ?? (string) $w, $d['warnings'] ?? [])];
    }

    private function week(User $u): array
    {
        $out = [];
        foreach ($this->plans->base($u)['days'] as $key => $d) {
            $out[$key] = $this->day($d);
        }

        return ['days' => $out];
    }

    private function overview(User $u): ?array
    {
        return $this->progress->overview($u);
    }

    private function trend(User $u, int $days): array
    {
        $o = $this->overview($u);
        if (! $o) {
            return ['status' => 'calibrating', 'note' => 'No weigh-ins yet.'];
        }
        $series = array_slice($o['series'], -($days + 1));
        $first = $series[0];
        $last = end($series);
        $weighIns = count(array_filter($series, fn ($r) => $r['scale'] !== null));

        return ['days' => $days, 'start_trend_kg' => round($first['trend'], 1), 'end_trend_kg' => round($last['trend'], 1), 'change_kg' => round($last['trend'] - $first['trend'], 1),
            'rate_pct_per_week_last_14d' => $o['summary']['ratePctPerWeek'] === null ? null : round((float) $o['summary']['ratePctPerWeek'], 2), 'goal_pct_per_week' => round($o['summary']['goalPctPerWeek'], 2),
            'phase' => $o['summary']['phase'], 'weigh_ins_in_range' => $weighIns, 'calibrating' => $o['summary']['calibrating']];
    }

    private function expenditure(User $u, int $days): array
    {
        $o = $this->overview($u);
        if (! $o) {
            return ['status' => 'calibrating', 'note' => 'No weigh-ins yet.'];
        }
        $series = array_slice($o['series'], -($days + 1));

        return ['days' => $days, 'tdee_kcal' => (int) $o['summary']['tdee'], 'plus_minus_kcal' => (int) $o['summary']['tdeeSd'], 'tdee_change_kcal' => (int) round(end($series)['tdee'] - $series[0]['tdee']), 'calibrating' => $o['summary']['calibrating'],
            'note' => 'Expenditure is estimated from your weight trend and logged food, so it needs weeks of data to settle.'];
    }

    private function checkin(User $u): array
    {
        $c = $this->overview($u)['checkin'] ?? null;

        return $c ? ['status' => $c['status'], 'current_target_kcal' => (int) $this->overview($u)['summary']['target'], 'proposed_kcal' => $c['proposedKcal'], 'change_kcal' => $c['change'], 'ideal_kcal' => $c['idealKcal'],
            'guard' => $c['guard'], 'hold_reasons' => $c['holdReasons'], 'reason_lines' => $c['lines'], 'calibrating' => $c['calibrating']] : ['status' => 'not_enough_data'];
    }

    private function trainingRange(User $u, int $days): array
    {
        $today = $this->todayDate($u);
        $rows = DB::table('workout_logs')->where('user_id', $u->id)->whereBetween('local_date', [date('Y-m-d', strtotime($today . " -{$days} days")), $today])->orderBy('local_date')->get();

        return ['days' => $days, 'done' => $rows->where('status', 'done')->count(), 'skipped' => $rows->where('status', 'skipped')->count(),
            'sessions' => $rows->map(fn ($r) => ['date' => (string) $r->local_date, 'type' => $r->type, 'intensity' => $r->intensity, 'minutes' => (int) $r->minutes, 'status' => $r->status, 'energy_estimate_kcal' => (int) round((float) ($r->kcal_override ?? $r->net_kcal))])->values()->all(),
            'note' => 'Energy is an estimate (about 25% either way) and is not added to the food target.'];
    }

    private function adherence(User $u): array
    {
        $o = $this->overview($u);
        if (! $o) {
            return ['status' => 'calibrating'];
        }

        return ['days_logged_last_7' => $o['adherence']['loggedDays7'], 'weigh_ins_last_14_days' => $o['adherence']['weighIns14'], 'days_counted_of_last_7' => $o['consistency']['counted'], 'days_within_15_pct_of_target' => $o['consistency']['within15'],
            'under_target_lately' => (bool) ($o['undereating']['flag'] ?? false), 'a_day_counts_when_logged_at_least_pct_of_target' => (int) round(Adherence::LOGGED_AT * 100)];
    }

    private function nextFuel(User $u): array
    {
        $today = $this->todayDate($u);
        $local = now($u->profile->tz);
        $nowMin = (int) $local->format('G') * 60 + (int) $local->format('i');
        $rem = function (string $date) use ($u) {
            $d = $this->plans->forDay($u, $date)['day'];
            $e = DB::table('food_logs')->where('user_id', $u->id)->where('local_date', $date)->selectRaw('coalesce(sum(kcal),0) k, coalesce(sum(protein),0) p, coalesce(sum(carbs),0) c, coalesce(sum(fat),0) f')->first();

            return ['kcal' => (float) $d['kcal'] - $e->k, 'protein' => (float) $d['protein'] - $e->p, 'carbs' => (float) $d['carbs'] - $e->c, 'fat' => (float) $d['fat'] - $e->f];
        };
        $when = 'today';
        $f = $this->fuel->forToday($u, $today, $nowMin, $rem($today));
        if ($f === null) {
            $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
            $f = $this->fuel->forToday($u, $tomorrow, $nowMin - 1440, $rem($tomorrow));
            $when = 'tomorrow';
        }
        if ($f === null) {
            return ['status' => 'no_session', 'note' => 'No session is planned today or tomorrow.'];
        }

        return ['when' => $when, 'session' => $f['next']['label'], 'start' => $f['next']['start'], 'minutes' => $f['next']['minutes'], 'minutes_until' => $f['next']['minutesUntil'],
            'timeline' => array_map(fn ($t) => ['at' => $t['at'], 'title' => $t['title'], 'what' => $t['text']], $f['timeline'] ?? []),
            'during' => $f['during']['note'], 'after' => ['protein_g' => $f['post']['proteinG'], 'carbs_g' => $f['post']['carbsG'] ?? null, 'within_hours' => $f['post']['withinHours']],
            'note' => 'These amounts come out of the day\'s targets, they are not extra.'];
    }

    private function assumptions(): array
    {
        return ['kcal_per_kg_of_weight_change' => (int) config('fuel.kcal_per_kg'), 'exercise_calories_added_to_food_target' => false, 'exercise_energy_error_pct' => 25,
            'protein_cap_pct_of_calories' => 35, 'target_moves_at_most_kcal_per_week' => 150, 'target_moves_pct_of_the_way_per_week' => 60,
            'rough_day_intake_error_kcal' => (int) IntakeModel::ROUGH_DAY_SD, 'min_days_of_data_for_checkin' => WeeklyCheckin::MIN_DAYS_OF_DATA, 'min_weigh_ins_in_14_days' => WeeklyCheckin::MIN_WEIGH_INS,
            'notes' => ['Muscle-gain ceilings and per-meal leucine or protein amounts are rules of thumb, not measurements.', 'Fluid amounts are guidelines, not prescriptions.', 'Not medical advice.']];
    }
}
