<?php

namespace App\Domain\Training;

/**
 * "What to lift next" from YOUR OWN logged sets. Pure PHP. These are suggestions from simple, visible rules, not a prescription and not a
 * validated program; you stay in charge and the reason is always shown.
 *
 * Double progression on the top-weight sets of your last session, judged against the rep range the routine plans (from Hevy):
 *  - every set at the top weight reached the TOP of the range      -> go up one smallest jump, aim for the bottom of the range again
 *  - all sets inside the range but not all at the top              -> same weight, add a rep to your weakest set
 *  - a set below the BOTTOM of the range                            -> repeat once; two sessions in a row -> drop about 5%
 *  - more than 3 weeks since you last did it                        -> repeat the last weight (a layoff is not a reason to jump)
 *  - no rep range in the routine                                    -> same weight, add a rep (a range is needed to say when to go up)
 * Effort (reps in reserve) never blocks a jump when you reached the top of the range; it sets how many reps to expect at the new weight and
 * is reported as "effort logged" or "reps only" so you know how much to trust it. Sets with no effort info are never guessed.
 *
 * Projection: a least-squares line through your last (up to 8) sessions' estimated 1RM, shown as a RANGE (half the trend to the full trend),
 * capped at 4% per 4 weeks because real gains slow down. Needs 4+ sessions over 14+ days. It is a rough extrapolation of your own trend, not a promise.
 */
final class Progression
{
    public const LAYOFF_DAYS = 21;

    public const DROP_FRACTION = 0.05;

    public const MONTHLY_CAP = 0.04;

    public const DEFAULT_RIR = 1;

    public const BIG_JUMP_PCT = 8.0;

    public const MIN_POINTS = 4;

    public const MIN_SPAN_DAYS = 14;

    /** Smallest jump from the exercise name (an ASSUMPTION; you can set your own per exercise). @param array<string,float> $steps */
    public static function defaultStep(string $title, array $steps, float $fallback): float
    {
        $t = strtolower($title);
        foreach (['dumbbell', 'barbell', 'smith', 'machine', 'cable', 'weighted'] as $k) {
            if (str_contains($t, $k) && isset($steps[$k])) {
                return (float) $steps[$k];
            }
        }

        return $fallback;
    }

    /** Reps you could expect at $kg for an estimated 1RM, leaving $rir in reserve (Epley run backwards). */
    public static function expectedReps(float $e1rm, float $kg, float $rir): int
    {
        if ($kg <= 0) {
            return 0;
        }

        return max(0, (int) floor(30 * ($e1rm / $kg - 1) - $rir + 1e-9));
    }

    /** The load you could use for $reps with $rir in reserve, rounded DOWN to the jump size. */
    public static function weightFor(float $e1rm, int $reps, float $rir, float $step): float
    {
        $raw = $e1rm / (1 + ($reps + $rir) / 30);

        return round(floor($raw / $step + 1e-9) * $step, 2);
    }

    /**
     * @param list<array{date:string,sets:list<array<string,mixed>>}> $sessions this exercise only, oldest first, deload weeks already left out
     * @param list<array<string,mixed>>|null $plan the routine's planned sets: [{type,kg,reps,rep_min,rep_max}]
     * @param array{min:int,max:int}|null $effort your reps-in-reserve plan for this week
     * @return array<string,mixed>
     */
    public static function recommend(array $sessions, ?array $plan, float $step, ?array $effort, string $today, ?string $status = null): array
    {
        $step = max(0.25, $step);
        $rows = [];
        foreach ($sessions as $s) {
            $w = array_values(array_filter($s['sets'], fn ($x) => ($x['type'] ?? 'normal') !== 'warmup' && isset($x['kg'], $x['reps']) && (float) $x['kg'] > 0 && (int) $x['reps'] >= 1));
            if ($w) {
                $rows[] = ['date' => $s['date'], 'sets' => $w];
            }
        }
        [$lo, $hi] = self::range($plan);
        $planKg = null;
        foreach (($plan ?? []) as $p) {
            if (($p['type'] ?? 'normal') !== 'warmup' && isset($p['kg']) && (float) $p['kg'] > 0) {
                $planKg = max($planKg ?? 0, (float) $p['kg']);
            }
        }
        $out = ['action' => 'no_load', 'kg' => null, 'reps_low' => $lo, 'reps_high' => $hi, 'expected_reps' => null, 'last' => null, 'reason' => '', 'notes' => [], 'confidence' => null, 'effort_target' => $effort, 'last_avg_rir' => null, 'jump_pct' => null, 'step' => $step];

        if (! $rows) {
            if ($planKg !== null) {
                return ['action' => 'start', 'kg' => $planKg, 'reason' => "Not logged yet. The routine plans {$planKg} kg: start there and log it so the next suggestion has your own numbers."] + $out;
            }

            return ['reason' => 'No logged sets with a weight, so there is nothing to progress (a timed or bodyweight exercise, or not done yet).'] + $out;
        }

        $last = $rows[count($rows) - 1];
        $top = max(array_map(fn ($x) => (float) $x['kg'], $last['sets']));
        $atTop = array_values(array_filter($last['sets'], fn ($x) => abs((float) $x['kg'] - $top) < 0.01));
        $reps = array_map(fn ($x) => (int) $x['reps'], $atTop);
        $minReps = min($reps);
        $rirs = array_values(array_filter(array_map(fn ($x) => Strength::rir($x), $atTop), fn ($r) => $r !== null));
        $avgRir = $rirs ? round(array_sum($rirs) / count($rirs), 1) : null;
        $out['last'] = ['date' => $last['date'], 'kg' => $top, 'reps' => $reps, 'sets_at_top' => count($atTop)];
        $out['last_avg_rir'] = $avgRir;
        $out['confidence'] = $avgRir !== null ? 'effort logged' : 'reps only';
        $days = (int) round((strtotime($today) - strtotime($last['date'])) / 86400);
        $bestSet = Strength::bestSet($last['sets']);
        $e1 = $bestSet['e1rm'] ?? null;
        $label = fn (float $kg, int $r) => self::fmt($kg) . ' kg × ' . $r;

        if ($days > self::LAYOFF_DAYS) {
            $out['action'] = 'repeat';
            $out['kg'] = $top;
            $out['reason'] = "It has been {$days} days since you last did this. Repeat " . self::fmt($top) . ' kg and rebuild before jumping.';

            return $out;
        }
        if ($lo === null || $hi === null) {
            $out['action'] = 'hold';
            $out['kg'] = $top;
            $out['reps_low'] = $out['reps_high'] = min(30, $minReps + 1);
            $out['reason'] = 'The routine has no rep range, so FUEL cannot say when to go up. Same weight, add a rep to your weakest set (' . $label($top, $minReps + 1) . '). Set a rep range in Hevy to get weight jumps.';

            return $out;
        }

        if ($minReps >= $hi) {
            $new = round($top + $step, 2);
            $pct = round(($new / $top - 1) * 100, 1);
            $tr = $effort ? ($effort['min'] + $effort['max']) / 2 : self::DEFAULT_RIR;
            $exp = $e1 !== null ? self::expectedReps($e1, $new, $tr) : null;
            $out['action'] = 'increase';
            $out['kg'] = $new;
            $out['reps_low'] = $lo;
            $out['expected_reps'] = $exp;
            $out['jump_pct'] = $pct;
            $out['reason'] = 'Every set at ' . self::fmt($top) . " kg reached {$hi} reps or more (the top of your range). Go up to " . self::fmt($new) . " kg and aim for {$lo} to {$hi} reps.";
            if ($exp !== null) {
                $out['notes'][] = 'At ' . self::fmt($new) . ' kg your estimated 1RM suggests about ' . $exp . ' reps with ' . self::fmt((float) $tr) . ' in reserve' . ($exp < $lo ? ', which is below the bottom of the range: that jump may be too big' : '') . '.';
            }
            if ($pct > self::BIG_JUMP_PCT) {
                $out['notes'][] = "That jump is {$pct}% of the weight. If you have smaller plates, a half step is fine.";
            }
            if ($avgRir === null) {
                $out['notes'][] = 'You did not log effort on those sets. Only go up if the last set felt like it had about ' . self::fmt((float) $tr) . ' reps left.';
            }

            return $out;
        }

        if ($minReps >= $lo) {
            $aim = min($hi, $minReps + 1);
            $out['action'] = 'hold';
            $out['kg'] = $top;
            $out['reps_low'] = $aim;
            $out['reps_high'] = $hi;
            $out['reason'] = 'Your weakest set at ' . self::fmt($top) . " kg was {$minReps} reps. Same weight, aim for {$aim} on every set; at {$hi} on all sets you go up.";
            if ($status === 'stalled') {
                $out['notes'][] = 'This lift is flagged stalled (no new best in a while). A deload week or a smaller jump are the usual options; both are your call.';
            }

            return $out;
        }

        // below the bottom of the range
        $prev = count($rows) >= 2 ? $rows[count($rows) - 2] : null;
        $missedBefore = false;
        if ($prev) {
            $topP = max(array_map(fn ($x) => (float) $x['kg'], $prev['sets']));
            $minP = min(array_map(fn ($x) => (int) $x['reps'], array_filter($prev['sets'], fn ($x) => abs((float) $x['kg'] - $topP) < 0.01)));
            $missedBefore = $topP >= $top - 0.01 && $minP < $lo;
        }
        if ($missedBefore) {
            $drop = min($top - $step, floor($top * (1 - self::DROP_FRACTION) / $step + 1e-9) * $step);
            $new = round(max($step, $drop), 2);
            $out['action'] = 'reduce';
            $out['kg'] = $new;
            $out['reps_low'] = $lo;
            $out['reps_high'] = $hi;
            $out['reason'] = "Below {$lo} reps at " . self::fmt($top) . ' kg two sessions in a row. Drop to ' . self::fmt($new) . " kg (about 5% lower) and build back up through {$lo} to {$hi} reps.";
            $out['notes'][] = 'Check sleep, food and how recovered you are before assuming the weight is wrong. A deload week is also an option.';

            return $out;
        }
        $out['action'] = 'repeat';
        $out['kg'] = $top;
        $out['reps_low'] = $lo;
        $out['reps_high'] = $hi;
        $out['reason'] = "Your weakest set at " . self::fmt($top) . " kg was {$minReps}, below the bottom of the range ({$lo}). Repeat the same weight once more; if it happens again FUEL will suggest a drop.";

        return $out;
    }

    /** @param list<array<string,mixed>>|null $plan @return array{0:?int,1:?int} lowest and highest planned reps across working sets */
    public static function range(?array $plan): array
    {
        $lo = $hi = null;
        foreach (($plan ?? []) as $p) {
            if (($p['type'] ?? 'normal') === 'warmup') {
                continue;
            }
            $a = $p['rep_min'] ?? $p['reps'] ?? null;
            $b = $p['rep_max'] ?? $p['reps'] ?? null;
            if ($a !== null) {
                $lo = $lo === null ? (int) $a : min($lo, (int) $a);
            }
            if ($b !== null) {
                $hi = $hi === null ? (int) $b : max($hi, (int) $b);
            }
        }

        return [$lo, $hi];
    }

    /**
     * Where your own estimated-1RM trend points. A range, never a single number.
     * @param list<array{date:string,e1rm:float}> $history oldest first (deload weeks already left out)
     * @return array<string,mixed>
     */
    public static function projection(array $history, ?int $topReps, float $step, ?array $effort): array
    {
        $h = array_slice($history, -8);
        $n = count($h);
        $base = ['status' => 'not_enough_data', 'sessions' => $n, 'latest' => $n ? $h[$n - 1]['e1rm'] : null, 'slope_per_week' => null, 'fit' => null, 'weeks' => [], 'note' => 'Needs ' . self::MIN_POINTS . ' or more sessions over at least ' . self::MIN_SPAN_DAYS . ' days to show a trend.'];
        if ($n < self::MIN_POINTS) {
            return $base;
        }
        $t0 = strtotime($h[0]['date']);
        $span = (strtotime($h[$n - 1]['date']) - $t0) / 86400;
        if ($span < self::MIN_SPAN_DAYS) {
            return $base;
        }
        $xs = array_map(fn ($p) => (strtotime($p['date']) - $t0) / 86400, $h);
        $ys = array_map(fn ($p) => (float) $p['e1rm'], $h);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxx = $sxy = $syy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($ys[$i] - $my);
            $syy += ($ys[$i] - $my) ** 2;
        }
        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;                     // kg per day
        $r2 = ($sxx > 0 && $syy > 0) ? ($sxy * $sxy) / ($sxx * $syy) : 0.0;
        $perWeek = round($slope * 7, 2);
        $latest = $ys[$n - 1];
        $base['latest'] = $latest;
        $base['slope_per_week'] = $perWeek;
        $base['fit'] = round($r2, 2);
        if ($perWeek <= 0) {
            return ['status' => 'flat_or_down', 'note' => 'Your estimated 1RM on this lift is flat or down over these sessions, so FUEL shows no upward projection.'] + $base;
        }
        $rir = $effort ? ($effort['min'] + $effort['max']) / 2 : self::DEFAULT_RIR;
        $weeks = [];
        foreach ([4, 8] as $w) {
            $cap = $latest * (1 + self::MONTHLY_CAP * ($w / 4));
            $high = min($cap, $latest + $perWeek * $w);
            $low = min($high, $latest + $perWeek * 0.5 * $w);
            $row = ['weeks' => $w, 'e1rm_low' => round($low, 1), 'e1rm_high' => round($high, 1)];
            if ($topReps !== null && $topReps > 0) {
                $row['reps'] = $topReps;
                $row['kg_low'] = self::weightFor($low, $topReps, $rir, $step);
                $row['kg_high'] = self::weightFor($high, $topReps, $rir, $step);
            }
            $weeks[] = $row;
        }

        return ['status' => $r2 < 0.25 ? 'noisy' : 'ok', 'weeks' => $weeks, 'note' => 'A rough extrapolation of your own trend, shown as a range (half the trend to the full trend, capped at ' . (int) (self::MONTHLY_CAP * 100) . '% per 4 weeks). ' . ($r2 < 0.25 ? 'Your sessions are noisy, so treat it with extra caution.' : 'Real progress usually slows down.')] + $base;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
