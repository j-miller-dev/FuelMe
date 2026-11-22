<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Plain observations about the last week of food, in the same calm tone as the nudges: what the numbers say and one thing to try, never a
 * grade. Only says what it can back up (e.g. fibre only when most of the food has fibre data). Pure.
 */
final class WeekInsights
{
    private const MAIN = ['breakfast', 'lunch', 'dinner'];

    /**
     * @param list<array{date:string,kcal:?float,target:float,protein:float,proteinTarget:float,fibre:float,kcalWithFibre:float,kcalLogged:float}> $days the last 7 completed days, oldest first; kcal null = not logged
     * @param array<string,array{days:int,protein:float}> $slots per meal slot: how many days it was used and the total protein in it
     * @return array<string,mixed>
     */
    public static function compute(array $days, array $slots, float $perMealG, int $logged14, int $weighIns14): array
    {
        $logged = array_values(array_filter($days, fn ($d) => $d['kcal'] !== null));
        $n = count($logged);
        $notes = [];
        $out = ['days' => $n, 'protein' => null, 'slots' => [], 'perMealG' => round($perMealG), 'fibre' => null, 'consistency' => ['logged7' => $n, 'logged14' => $logged14, 'weighIns14' => $weighIns14], 'notes' => []];

        if ($n >= 3) {
            $avg = array_sum(array_column($logged, 'protein')) / $n;
            $tgt = array_sum(array_column($logged, 'proteinTarget')) / $n;
            $pct = $tgt > 0 ? $avg / $tgt * 100 : 0.0;
            $out['protein'] = ['avg' => round($avg), 'target' => round($tgt), 'pct' => round($pct)];
            if ($pct < 90) {
                $notes[] = sprintf('Protein averaged %d g a day, %d%% of your %d g target. Adding it to the meals that are lightest on protein is the easiest fix.', round($avg), round($pct), round($tgt));
            } else {
                $notes[] = sprintf('Protein is on track: about %d g a day against a %d g target.', round($avg), round($tgt));
            }

            foreach (self::MAIN as $s) {
                if (($slots[$s]['days'] ?? 0) >= 3) {
                    $out['slots'][$s] = round($slots[$s]['protein'] / $slots[$s]['days']);
                }
            }
            $lows = array_keys(array_filter($out['slots'], fn ($g) => $g < 0.6 * $perMealG));
            if ($lows) {
                $notes[] = sprintf('%s %s about %s g of protein. Spreading it across meals, around %d g each, works better than one big dose.', implode(' and ', array_map('ucfirst', $lows)), count($lows) === 1 ? 'averages' : 'average', implode(' and ', array_map(fn ($s) => $out['slots'][$s], $lows)), round($perMealG));
            }

            $kcalAll = array_sum(array_column($logged, 'kcalLogged'));
            $kcalFibre = array_sum(array_column($logged, 'kcalWithFibre'));
            if ($kcalAll > 0 && $kcalFibre / $kcalAll >= 0.6) {
                $fib = array_sum(array_column($logged, 'fibre')) / $n;
                $guide = 14.0 * (array_sum(array_column($logged, 'kcal')) / $n) / 1000.0;   // 14 g per 1000 kcal: a guide, not a rule
                $out['fibre'] = ['avg' => round($fib), 'guide' => round($guide), 'coverage' => round($kcalFibre / $kcalAll * 100)];
                if ($fib < 0.7 * $guide) {
                    $notes[] = sprintf('Fibre averaged %d g a day against a guide of about %d g. Oats, fruit, legumes and wholegrain bread are easy places to add it.', round($fib), round($guide));
                }
            }
        } else {
            $notes[] = 'Log food on a few more days and this will start showing what your week looks like.';
        }

        if ($n >= 5) {
            $notes[] = sprintf('You logged food on %d of the last 7 days. That is a solid week.', $n);
        } elseif ($n > 0) {
            $notes[] = sprintf('You logged food on %d of the last 7 days. Even rough entries help, and a missed day is just a gap, not a failure.', $n);
        }
        $out['notes'] = $notes;

        return $out;
    }
}
