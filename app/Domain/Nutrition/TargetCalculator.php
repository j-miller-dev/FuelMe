<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

final class TargetCalculator
{
    /** Mifflin-St Jeor BMR * activity multiplier: only used as the day-one prior. */
    public static function priorTdee(float $kg, float $cm, int $age, string $sex, float $activity = 1.4): float
    {
        $bmr = 10 * $kg + 6.25 * $cm - 5 * $age + ($sex === 'male' ? 5 : -161);
        return $bmr * $activity;
    }

    /**
     * Weekly check-in. $ratePctPerWeek: -0.5 = lose 0.5% bodyweight/week, +0.25 = gain.
     * Moves only part of the way to the ideal each week and caps the step, so one noisy
     * week can't whiplash the user.
     */
    public static function weeklyTarget(
        float $tdee,
        float $trendKg,
        float $ratePctPerWeek,
        ?float $previousTarget = null,
        float $kcalPerKg = 7700.0,
        float $smoothing = 0.6,
        float $maxStep = 150.0,
        float $floor = 1400.0,
    ): float {
        $dailyBalance = ($ratePctPerWeek / 100) * $trendKg * $kcalPerKg / 7;
        $ideal = max($floor, $tdee + $dailyBalance);
        if ($previousTarget === null) {
            return round($ideal);
        }
        $step = ($ideal - $previousTarget) * $smoothing;
        $step = max(-$maxStep, min($maxStep, $step));
        return round($previousTarget + $step);
    }
}
