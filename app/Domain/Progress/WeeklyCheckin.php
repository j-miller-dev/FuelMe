<?php
declare(strict_types=1);

namespace App\Domain\Progress;

use App\Domain\Nutrition\TargetCalculator;

/**
 * The weekly check-in (BLUEPRINT 9.9). Decides whether your calorie target may change and by how much, and says why in plain lines.
 * It never blocks on perfect data: it states what it can and cannot conclude. Pure; the target maths is TargetCalculator::weeklyTarget
 * (move 60% of the way to the ideal, at most 150 kcal a week).
 */
final class WeeklyCheckin
{
    public const MIN_WEIGH_INS = 3;      // in the last 14 days
    public const MIN_LOGGED_DAYS = 5;    // of the last 7 completed days
    public const MIN_DAYS_OF_DATA = 14;
    public const MIN_DAYS_BETWEEN = 7;
    public const CALIBRATED_SD = 150.0;
    public const GUARD_STEP = 100.0;

    /**
     * @param array{
     *   trendKg:float, tdee:float, tdeeSd:float, phase:string, goalPctPerWeek:float, previousTarget:float,
     *   tdeeAtLastTarget:?float, slope14:?array, slopePrev14:?array, weighIns14:int, loggedDays7:int, daysOfData:int, daysSinceChange:int,
     *   undereating?:bool, kcalPerKg?:float, floor?:float
     * } $in
     * @return array{status:string, holdReasons:list<string>, proposedKcal:int, change:int, idealKcal:int, guard:?string, calibrating:bool, lines:list<string>}
     */
    public static function evaluate(array $in): array
    {
        $kPerKg = $in['kcalPerKg'] ?? 7700.0;
        $floor = $in['floor'] ?? 1400.0;
        $prev = (int) round($in['previousTarget']);
        $calibrating = $in['tdeeSd'] >= self::CALIBRATED_SD;

        $hold = [];
        if ($in['daysOfData'] < self::MIN_DAYS_OF_DATA) {
            $hold[] = sprintf('There are only %d days of data so far. A check-in needs %d to tell a real trend from water.', $in['daysOfData'], self::MIN_DAYS_OF_DATA);
        }
        if ($in['weighIns14'] < self::MIN_WEIGH_INS) {
            $hold[] = sprintf('You weighed in %d time%s in the last 14 days. Aim for %d or more.', $in['weighIns14'], $in['weighIns14'] === 1 ? '' : 's', self::MIN_WEIGH_INS);
        }
        if ($in['loggedDays7'] < self::MIN_LOGGED_DAYS) {
            $hold[] = sprintf('Food was logged on %d of the last 7 days. Aim for %d or more.', $in['loggedDays7'], self::MIN_LOGGED_DAYS);
        }

        $lines = [];
        $ideal = (int) TargetCalculator::weeklyTarget($in['tdee'], $in['trendKg'], $in['goalPctPerWeek'], null, $kPerKg, 1.0, 100000.0, $floor);

        if ($hold) {
            return ['status' => 'hold', 'holdReasons' => $hold, 'proposedKcal' => $prev, 'change' => 0, 'idealKcal' => $ideal, 'guard' => null, 'calibrating' => $calibrating,
                'lines' => array_merge(['Your target stays at ' . $prev . ' kcal for now.'], $hold, ['Nothing is wrong. The check-in just needs a little more to go on.'])];
        }
        if ($in['daysSinceChange'] < self::MIN_DAYS_BETWEEN) {
            $wait = self::MIN_DAYS_BETWEEN - $in['daysSinceChange'];

            return ['status' => 'wait', 'holdReasons' => [], 'proposedKcal' => $prev, 'change' => 0, 'idealKcal' => $ideal, 'guard' => null, 'calibrating' => $calibrating,
                'lines' => [sprintf('Your target changed %d day%s ago. Targets move at most once a week, so the next check-in is in %d day%s.', $in['daysSinceChange'], $in['daysSinceChange'] === 1 ? '' : 's', $wait, $wait === 1 ? '' : 's')]];
        }

        // 1. Where the numbers stand.
        $lines[] = sprintf('Estimated expenditure is %d kcal/day (give or take %d)%s.', round($in['tdee']), round($in['tdeeSd']),
            $in['tdeeAtLastTarget'] !== null ? sprintf(', %s %d kcal since your last target', $in['tdee'] >= $in['tdeeAtLastTarget'] ? 'up' : 'down', abs(round($in['tdee'] - $in['tdeeAtLastTarget']))) : '');
        $s = $in['slope14'];
        $lines[] = sprintf('Trend weight is %.1f kg and moved %+.2f kg over %d days, which is %+.2f%% a week. Your goal is %+.2f%% a week.', $in['trendKg'], $s['deltaKg'], $s['days'], $s['pctPerWeek'], $in['goalPctPerWeek']);

        // 2. What intake that goal needs, then the smoothed step.
        $lines[] = sprintf('To match your goal rate, intake should be about %d kcal/day.', $ideal);
        $proposed = (int) TargetCalculator::weeklyTarget($in['tdee'], $in['trendKg'], $in['goalPctPerWeek'], (float) $prev, $kPerKg, 0.6, 150.0, $floor);

        // 3. Guards (bulk): clearly too fast for two weeks, or clearly too slow with good adherence.
        $guard = null;
        $goal = $in['goalPctPerWeek'];
        if ($in['phase'] === 'bulk' && $goal > 0) {
            $tooFast = $s['pctPerWeek'] > 1.5 * $goal && $in['slopePrev14'] !== null && $in['slopePrev14']['pctPerWeek'] > 1.5 * $goal;
            $tooSlow = $s['pctPerWeek'] < 0.5 * $goal && $in['loggedDays7'] >= 6;   // 6 of 7 is about 85%
            if ($tooFast && $proposed > $prev - self::GUARD_STEP) {
                $proposed = (int) max($floor, max($prev - 150, $prev - self::GUARD_STEP));
                $guard = 'bulk_too_fast';
                $lines[] = 'You have been gaining more than 1.5 times your goal rate for two weeks, so the target comes down a little more.';
            } elseif ($tooSlow && $proposed < $prev + self::GUARD_STEP) {
                $proposed = (int) min($prev + 150, $prev + self::GUARD_STEP);
                $guard = 'bulk_too_slow';
                $lines[] = 'You are gaining less than half your goal rate with food logged most days, so the target goes up a little more.';
            }
        }

        // Raising a target you are not reaching changes nothing but the gap. Close the gap first (that is what the nudge is for).
        if (($in['undereating'] ?? false) && $proposed > $prev) {
            $proposed = $prev;
            $guard = null;
            $lines[] = 'You have been eating under your current target, so raising it would not help yet. Getting closer to the current number comes first.';
        }

        $change = $proposed - $prev;
        $held = abs($ideal - $proposed) > 1 && $guard === null;
        $lines[] = $change === 0
            ? sprintf('No change: %d kcal/day is already where it should be.', $prev)
            : sprintf('Target moves from %d to %d kcal/day (%+d).%s', $prev, $proposed, $change, $held ? ' We move 60% of the way and at most 150 kcal a week, so one noisy week cannot whiplash you.' : '');
        if ($calibrating) {
            $lines[] = 'The expenditure estimate is still settling, so treat the numbers as a guide. The weekly step limit keeps that safe.';
        }

        return ['status' => 'ready', 'holdReasons' => [], 'proposedKcal' => $proposed, 'change' => $change, 'idealKcal' => $ideal, 'guard' => $guard, 'calibrating' => $calibrating, 'lines' => $lines];
    }
}
