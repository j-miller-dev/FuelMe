<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * The structured weekly review (BLUEPRINT 9A.3) and its deterministic note. The JSON holds every number; the note only interpolates them,
 * so a model that rewrites it can be checked: any number it uses must already be in the JSON (see groundedNumbers). Pure.
 */
final class WeeklyReview
{
    /**
     * @param array<string,mixed> $in see WeeklyReviewService for the fields
     * @return array<string,mixed>
     */
    public static function build(array $in): array
    {
        $rate = $in['ratePctPerWeek'];
        $goal = (float) $in['goalPctPerWeek'];
        $pace = match (true) {
            $rate === null => 'unknown',
            $goal == 0.0 => abs($rate) <= 0.15 ? 'on_pace' : 'off_pace',
            abs($rate - $goal) <= 0.15 => 'on_pace',
            ($rate - $goal) * ($goal > 0 ? 1 : -1) > 0 => 'ahead',
            default => 'behind',
        };

        return [
            'week_start' => $in['weekStart'], 'week_end' => $in['weekEnd'], 'phase' => $in['phase'],
            'trend_kg' => $in['trendKg'], 'rate_pct_per_week' => $rate, 'goal_pct_per_week' => $goal, 'pace' => $pace,
            'days_counted' => $in['daysCounted'], 'days_total' => $in['daysTotal'], 'days_within_15' => $in['daysWithin15'],
            'avg_protein_g' => $in['avgProtein'], 'protein_target_g' => $in['proteinTarget'],
            'undereating' => (bool) $in['undereating'], 'sessions_done' => $in['sessionsDone'], 'sessions_skipped' => $in['sessionsSkipped'],
            'tdee' => $in['tdee'], 'tdee_change' => $in['tdeeChange'], 'calibrating' => (bool) $in['calibrating'],
            'checkin_status' => $in['checkinStatus'], 'target_kcal' => $in['targetKcal'], 'proposed_kcal' => $in['proposedKcal'],
            'reasons' => $in['reasons'],
        ];
    }

    /** Deterministic note: what happened, what it means, one or two suggested actions. Always under 150 words. */
    public static function template(array $r): string
    {
        $happened = [];
        if ($r['trend_kg'] !== null) {
            $happened[] = sprintf('Your trend weight is %.1f kg%s.', $r['trend_kg'], $r['rate_pct_per_week'] === null ? '' : sprintf(', moving %.2f%% of body weight a week against a goal of %.2f%%', $r['rate_pct_per_week'], $r['goal_pct_per_week']));
        }
        $happened[] = sprintf('Food counted on %d of %d days (%d within 15%% of target).', $r['days_counted'], $r['days_total'], $r['days_within_15']);
        if ($r['avg_protein_g'] !== null) {
            $happened[] = sprintf('Protein averaged %d g against %d g.', $r['avg_protein_g'], $r['protein_target_g']);
        }
        if ($r['sessions_done'] + $r['sessions_skipped'] > 0) {
            $happened[] = sprintf('You trained %d time%s%s.', $r['sessions_done'], $r['sessions_done'] === 1 ? '' : 's', $r['sessions_skipped'] ? sprintf(' and skipped %d', $r['sessions_skipped']) : '');
        }

        $means = match (true) {
            $r['calibrating'] => 'The app is still calibrating your expenditure, so read the trend loosely.',
            $r['pace'] === 'on_pace' => 'That is on pace for your goal.',
            $r['pace'] === 'ahead' => 'You are moving faster than planned.',
            $r['pace'] === 'behind' => 'You are moving slower than planned.',
            $r['pace'] === 'off_pace' => 'Your weight is drifting while the goal is to hold it.',
            default => 'There is not enough weigh-in data yet to judge the pace.',
        };

        $actions = [];
        if ($r['days_counted'] < 5) {
            $actions[] = 'Log something on more days, even a rough day counts.';
        }
        if ($r['undereating']) {
            $actions[] = 'You have been under target on most days, so eat a little more before changing anything.';
        }
        if ($r['checkin_status'] === 'ready' && $r['proposed_kcal'] !== null && $r['proposed_kcal'] !== $r['target_kcal']) {
            $actions[] = sprintf('Consider moving your target from %d to %d kcal in the weekly check-in.', $r['target_kcal'], $r['proposed_kcal']);
        }
        if (! $actions) {
            $actions[] = 'Keep going as you are.';
        }

        return implode(' ', $happened) . ' ' . $means . ' ' . implode(' ', array_slice($actions, 0, 2));
    }

    /** Numbers inside a piece of text (integers and decimals), as floats. */
    public static function numbersIn(string $text): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $text, $m);

        return array_map('floatval', $m[0]);
    }

    /**
     * True only if every number in the note appears in the review (as is, or rounded to 0 or 1 decimal) or in the review's own text lines.
     * This is the guard that stops a model from inventing a figure.
     */
    public static function groundedNumbers(string $note, array $review): bool
    {
        $allowed = [];
        array_walk_recursive($review, function ($v) use (&$allowed) {
            if (is_int($v) || is_float($v)) {
                foreach ([0, 1, 2] as $d) {
                    $allowed[] = round((float) $v, $d);
                    $allowed[] = round(abs((float) $v), $d);
                }
            } elseif (is_string($v)) {
                $allowed = array_merge($allowed, self::numbersIn($v));
            }
        });
        // Counts such as "1-2 actions" or "15%" are fixed wording, not data.
        $allowed = array_merge($allowed, [1.0, 2.0, 7.0, 15.0]);
        foreach (self::numbersIn($note) as $n) {
            if (! in_array(round($n, 2), array_map(fn ($a) => round($a, 2), $allowed), true)) {
                return false;
            }
        }

        return true;
    }
}
