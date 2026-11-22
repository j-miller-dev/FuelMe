<?php
declare(strict_types=1);

namespace App\Domain\Progress;

use App\Domain\Nutrition\GoalPlanner;

/**
 * Event mode (BLUEPRINT 9A.7): "be X kg by this date". Works out the weekly rate that needs, checks it against the same safe caps the goal
 * planner uses, and says one of: on_track, needs_rate_change, not_achievable_safely. No acute dehydration or "weight-cut" advice, ever:
 * a rate over the cap is never offered as a plan, only a later date or a smaller change.
 * Pure PHP: no framework imports.
 */
final class EventPlanner
{
    /** A realised rate this far (in %BW/week) from the required one counts as "needs a change of pace". */
    public const PACE_TOLERANCE = 0.15;

    /**
     * @param ?float $realisedPctPerWeek your actual 14-day trend rate (signed), null if not known yet
     * @return array{verdict:string,direction:string,deltaKg:float,days:int,requiredPctPerWeek:float,capPctPerWeek:float,
     *   earliestDays:?int,achievableKgByDate:float,kcalTarget:int,lines:list<string>}
     */
    public static function assess(float $trendKg, float $targetKg, int $days, string $experience, float $tdee, ?float $realisedPctPerWeek = null, float $kcalPerKg = 7700.0): array
    {
        $g = GoalPlanner::assess($trendKg, $targetKg, $days, $experience, $tdee, $kcalPerKg);
        $required = $g['ratePctPerWeek'];
        $over = $g['direction'] !== 'maintain' && abs($required) > $g['capPctPerWeek'] + 1e-9;

        $verdict = 'on_track';
        if ($over) {
            // Events get no "land a bit short" middle band: past the cap it is simply not safely achievable by that date.
            $verdict = 'not_achievable_safely';
        } elseif ($realisedPctPerWeek !== null && $g['direction'] !== 'maintain' && abs($realisedPctPerWeek - $required) > self::PACE_TOLERANCE) {
            $verdict = 'needs_rate_change';
        }

        $lines = [];
        if ($g['direction'] === 'maintain') {
            $lines[] = 'You are already at that weight, so the plan is to hold it.';
        } else {
            $lines[] = sprintf('To reach %.1f kg in %d days you would need to %s about %.2f%% of body weight per week. The safe ceiling for you is %.2f%%.',
                $targetKg, $days, $g['direction'] === 'loss' ? 'lose' : 'gain', abs($required), $g['capPctPerWeek']);
        }
        if ($verdict === 'not_achievable_safely') {
            $lines[] = sprintf('That is not achievable safely by that date. At the safe rate you would be near %.1f kg by then.', $g['achievableKgByDate']);
            $lines[] = sprintf('Options: move the date out to at least %d days from now, or aim for a smaller change.', (int) $g['earliestDays']);
            $lines[] = 'This app does not plan rapid weigh-in cuts or dehydration. For a weight class, talk to a coach or doctor.';
        } elseif ($verdict === 'needs_rate_change') {
            $lines[] = sprintf('Your last two weeks moved at %.2f%% per week, so the pace needs to %s to make the date.', $realisedPctPerWeek, abs($realisedPctPerWeek) < abs($required) ? 'pick up' : 'ease off');
        } elseif ($g['direction'] !== 'maintain') {
            $lines[] = 'The date is reachable at a safe rate.';
        }

        return ['verdict' => $verdict, 'direction' => $g['direction'], 'deltaKg' => $g['deltaKg'], 'days' => $days, 'requiredPctPerWeek' => $required,
            'capPctPerWeek' => $g['capPctPerWeek'], 'earliestDays' => $g['earliestDays'], 'achievableKgByDate' => $g['achievableKgByDate'], 'kcalTarget' => $g['kcalTarget'], 'lines' => $lines];
    }

    /**
     * A bulk-then-cut timeline: each phase starts from the previous phase's target weight.
     * @param list<array{until:string,target_kg:float}> $phases ordered by date
     * @return list<array{until:string,target_kg:float}&array<string,mixed>>
     */
    public static function timeline(float $trendKg, string $today, array $phases, string $experience, float $tdee, ?float $realised = null): array
    {
        $out = [];
        $from = $trendKg;
        $start = $today;
        foreach ($phases as $i => $p) {
            $days = (int) round((strtotime($p['until']) - strtotime($start)) / 86400);
            if ($days < 1) {
                $out[] = ['until' => $p['until'], 'target_kg' => $p['target_kg'], 'verdict' => 'not_achievable_safely', 'lines' => ['This phase ends on or before the previous one.']];
                continue;
            }
            $out[] = ['until' => $p['until'], 'target_kg' => $p['target_kg']] + self::assess($from, (float) $p['target_kg'], $days, $experience, $tdee, $i === 0 ? $realised : null);
            $from = (float) $p['target_kg'];
            $start = $p['until'];
        }

        return $out;
    }
}
