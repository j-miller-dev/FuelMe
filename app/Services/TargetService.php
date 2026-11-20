<?php

namespace App\Services;

use App\Domain\Nutrition\DayOnePrior;
use App\Domain\Nutrition\GoalRates;
use App\Domain\Nutrition\MacroPlanner;
use App\Domain\Nutrition\TargetCalculator;

/**
 * Glue between validated profile/goal input and the pure engine (app/Domain/Nutrition).
 * No database access here: it takes plain arrays and returns plain arrays so it is trivial to test.
 */
class TargetService
{
    /**
     * @param array{sex:string,dob:string,height_cm:float,weight_kg:float,body_fat_pct:?float,job:string,training_age:string,
     *   lifting_style:string,phase:string,rate_pct_per_week:?float,protein_g_per_kg:?float,carb_mode?:string} $in
     * @return array<string,mixed>
     */
    public function compute(array $in, string $today): array
    {
        $kg = (float) $in['weight_kg'];
        $cm = (float) $in['height_cm'];
        $bf = $in['body_fat_pct'] ?? null;
        $experience = GoalRates::experience($in['training_age']);
        $rate = $in['rate_pct_per_week'] ?? GoalRates::default($in['phase'], $experience);
        $week = config('fuel.seed_week.'.$in['lifting_style']) ?? config('fuel.seed_week.hit');
        $age = DayOnePrior::ageOn($in['dob'], $today);
        $kcalPerKg = (float) config('fuel.kcal_per_kg');

        $prior = DayOnePrior::estimate($kg, $cm, $age, $in['sex'], $bf, $in['job'], $week);
        $dailyBalance = round($rate / 100 * $kg * $kcalPerKg / 7);
        $kcalTarget = TargetCalculator::weeklyTarget($prior['tdee'], $kg, $rate, null, $kcalPerKg);

        $plan = MacroPlanner::plan([
            'weightKg' => $kg,
            'sex' => $in['sex'],
            'bodyFatPct' => $bf,
            'phase' => $in['phase'],
            'kcalTarget' => $kcalTarget,
            'week' => $week,
            'proteinGPerKg' => $in['protein_g_per_kg'] ?? null,
            'carbMode' => $in['carb_mode'] ?? 'flexible',
        ]);

        $warnings = $plan['warnings'];
        $surplusPct = $prior['tdee'] > 0 ? ($kcalTarget - $prior['tdee']) / $prior['tdee'] * 100 : 0.0;
        if ($in['phase'] === 'bulk' && $surplusPct > 20) {
            $warnings[] = ['level' => 'warn', 'code' => 'surplus_over_20', 'msg' => 'Surplus is over 20% of estimated expenditure, which mostly adds fat.'];
        }

        return [
            'age' => $age,
            'experience' => $experience,
            'ratePctPerWeek' => $rate,
            'dailyBalanceKcal' => $dailyBalance,
            'prior' => $prior,
            'kcalTarget' => (int) $kcalTarget,
            'plan' => ['days' => $plan['days'], 'weekly' => $plan['weekly']],
            'warnings' => $warnings,
            'why' => $this->why($prior, $rate, $dailyBalance, $kcalTarget, $plan['weekly'], $in['phase']),
        ];
    }

    /**
     * Re-plan the macros around a NEW calorie target (the weekly check-in). Same planner as onboarding, so protein and fat rules and the
     * carb periodisation are identical; only the calorie target and the body weight (now the trend weight) change.
     *
     * @param array{sex:string,body_fat_pct:?float,lifting_style:string,phase:string,protein_g_per_kg:?float,carb_mode?:string} $in
     * @return array{days:array,weekly:array,warnings:array}
     */
    public function replan(array $in, float $trendKg, float $kcalTarget): array
    {
        $week = config('fuel.seed_week.' . $in['lifting_style']) ?? config('fuel.seed_week.hit');
        $plan = MacroPlanner::plan([
            'weightKg' => $trendKg, 'sex' => $in['sex'], 'bodyFatPct' => $in['body_fat_pct'] ?? null, 'phase' => $in['phase'], 'kcalTarget' => $kcalTarget,
            'week' => $week, 'proteinGPerKg' => $in['protein_g_per_kg'] ?? null, 'carbMode' => $in['carb_mode'] ?? 'flexible',
        ]);

        return ['days' => $plan['days'], 'weekly' => $plan['weekly'], 'warnings' => $plan['warnings']];
    }

    /**
     * Deterministic "why" lines (BLUEPRINT principle 2). Every number is read from the engine output.
     *
     * @return list<string>
     */
    private function why(array $prior, float $rate, float $balance, float $target, array $weekly, string $phase): array
    {
        $method = $prior['rmrMethod'] === 'katch_mcardle' ? 'Katch-McArdle (uses your body fat)' : 'Mifflin-St Jeor';
        $lines = [
            sprintf('Resting energy %d kcal (%s).', $prior['rmr'], $method),
            sprintf('Your job and daily movement multiply that by %.2f%s.', $prior['pal'], $prior['palAssumed'] ? ' (a default, your job was not recognised)' : ''),
            sprintf('Planned training adds about %d kcal/day on average.', $prior['exerciseKcalPerDay']),
            sprintf('That gives a starting estimate of %d kcal/day (give or take %d). It is a guess until your own weigh-ins and food logs replace it.', $prior['tdee'], $prior['sd']),
        ];
        $lines[] = match ($phase) {
            'bulk' => sprintf('Your bulk rate of %.2f%% bodyweight per week adds about %d kcal/day.', $rate, abs($balance)),
            'cut' => sprintf('Your cut rate of %.2f%% bodyweight per week removes about %d kcal/day.', abs($rate), abs($balance)),
            default => 'Maintaining: no planned change in weight.',
        };
        $lines[] = sprintf('Weekly average target: %d kcal/day. Protein is set first (%d g), then fat (%d g), and carbohydrate takes the rest and moves with your training days.', $weekly['kcalAvg'], $weekly['protein'], $weekly['fat']);
        $lines[] = 'Exercise calories are not added on top: your expenditure estimate already includes your usual training.';

        return $lines;
    }
}
