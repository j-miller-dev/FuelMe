<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * "Reach X kg (or add Y kg of muscle) by date D" -> required rate, safety verdict, calorie target.
 * Rate caps follow BLUEPRINT 9.3: bulk novice 0.5 / intermediate 0.35 / advanced 0.25 %BW/wk, cut <= 1.0.
 * The monthly muscle ceilings are the widely cited Aragon rule of thumb (% bodyweight/month); they are
 * heuristics, not measurements. The lean-mass fraction of a weight gain is an editable ASSUMPTION.
 */
final class GoalPlanner
{
    public const BULK_CAP = ['novice' => 0.5, 'intermediate' => 0.35, 'advanced' => 0.25];
    public const CUT_CAP = 1.0;
    /** Upper end of typical natural muscle gain, % bodyweight per month (Aragon rule of thumb). */
    public const MUSCLE_CEILING_PCT_MONTH = ['novice' => 1.5, 'intermediate' => 1.0, 'advanced' => 0.5];

    /**
     * @return array{direction:string,deltaKg:float,ratePctPerWeek:float,capPctPerWeek:float,status:string,
     *   planRatePctPerWeek:float,balanceKcalPerDay:float,kcalTarget:int,surplusPctOfTdee:float,warnings:list<string>,
     *   earliestDays:?int,achievableKgByDate:float}
     */
    public static function assess(float $trendKg, float $targetKg, int $days, string $experience, float $tdee, float $kcalPerKg = 7700.0): array
    {
        $days = max($days, 1);
        $delta = $targetKg - $trendKg;
        $dir = $delta > 0.05 ? 'gain' : ($delta < -0.05 ? 'loss' : 'maintain');
        $sign = $dir === 'loss' ? -1.0 : 1.0;
        $weeks = $days / 7;
        $rate = $dir === 'maintain' ? 0.0 : $delta / $trendKg / $weeks * 100;
        $cap = $dir === 'loss' ? self::CUT_CAP : self::BULK_CAP[$experience];
        $abs = abs($rate);
        $status = match (true) {
            $dir === 'maintain' || $abs <= $cap + 1e-9 => 'on_track',
            $abs <= 2 * $cap => 'too_fast',
            default => 'not_achievable',
        };
        $plan = $sign * min($abs, $cap);
        $balance = $plan / 100 * $trendKg * $kcalPerKg / 7;
        $pct = $balance / $tdee * 100;
        $warnings = [];
        if ($status !== 'on_track') {
            $warnings[] = $status === 'too_fast'
                ? 'That date needs more than the safe weekly rate. Your plan uses the cap, so you will land short unless you move the date or the target.'
                : 'Not achievable safely by that date. Move the date out or lower the target.';
        }
        if ($dir === 'gain' && $pct > 20) $warnings[] = 'Surplus is over 20% of expenditure, which mostly adds fat.';
        $earliest = $dir === 'maintain' ? null : (int) ceil(abs($delta) / ($cap / 100 * $trendKg) * 7);
        return [
            'direction' => $dir, 'deltaKg' => round($delta, 2), 'ratePctPerWeek' => round($rate, 3), 'capPctPerWeek' => $cap, 'status' => $status,
            'planRatePctPerWeek' => round($plan, 3), 'balanceKcalPerDay' => round($balance), 'kcalTarget' => (int) round($tdee + $balance),
            'surplusPctOfTdee' => round($pct, 1), 'warnings' => $warnings, 'earliestDays' => $earliest,
            'achievableKgByDate' => round($trendKg + $sign * $cap / 100 * $trendKg * $weeks, 2),
        ];
    }

    /**
     * Muscle goal -> weight goal. Scale weight cannot see muscle, so this converts using an assumed lean fraction.
     * @return array{targetKg:float,muscleKgPerMonth:float,musclePctBwPerMonth:float,ceilingPctPerMonth:float,muscleStatus:string}
     */
    public static function fromMuscle(float $muscleKg, float $leanFraction, float $trendKg, int $days, string $experience): array
    {
        $leanFraction = max(0.3, min(0.8, $leanFraction));
        $months = max($days, 1) / 30.44;
        $perMonth = $muscleKg / $months;
        $pct = $perMonth / $trendKg * 100;
        $ceil = self::MUSCLE_CEILING_PCT_MONTH[$experience];
        return ['targetKg' => round($trendKg + $muscleKg / $leanFraction, 2), 'muscleKgPerMonth' => round($perMonth, 2),
            'musclePctBwPerMonth' => round($pct, 2), 'ceilingPctPerMonth' => $ceil, 'muscleStatus' => $pct <= $ceil ? 'within' : 'ambitious'];
    }
}
