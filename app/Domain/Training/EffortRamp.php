<?php

namespace App\Domain\Training;

/**
 * Your own effort plan for lifting: a repeating cycle of weeks, each with a range of reps in reserve (RIR), for example 2-3, then 1-2, then 0-1 (to failure).
 * You set it; FUEL does not choose it. The cycle repeats after its last week (an assumption, you can change the length). Pure PHP.
 * Ramp shape: ['start' => 'Y-m-d' (any date, taken as the start of week 1), 'weeks' => [[min, max], ...]]
 */
final class EffortRamp
{
    public const TOLERANCE = 0.5;

    /** @return array{week:int,of:int,min:int,max:int,label:string}|null null before the start date or with no weeks */
    public static function weekFor(?array $ramp, string $date): ?array
    {
        $weeks = $ramp['weeks'] ?? [];
        if (! $weeks || empty($ramp['start'])) {
            return null;
        }
        $days = (int) floor((strtotime($date . ' 12:00 UTC') - strtotime(self::monday((string) $ramp['start']) . ' 12:00 UTC')) / 86400);
        if ($days < 0) {
            return null;
        }
        $i = intdiv($days, 7) % count($weeks);
        [$min, $max] = $weeks[$i];

        return ['week' => $i + 1, 'of' => count($weeks), 'min' => (int) $min, 'max' => (int) $max, 'label' => self::label((int) $min, (int) $max)];
    }

    public static function label(int $min, int $max): string
    {
        if ($min === 0 && $max === 0) {
            return 'to failure';
        }
        if ($min === 0) {
            return "to failure or {$max} rep" . ($max === 1 ? '' : 's') . ' in reserve';
        }

        return $min === $max ? "{$min} rep" . ($min === 1 ? '' : 's') . ' in reserve' : "{$min}-{$max} reps in reserve";
    }

    /**
     * Did the hardest set of each exercise land in the planned range? Uses only sets where effort was logged.
     * @param list<int> $hardestRirs the lowest reps-in-reserve per exercise
     * @return array{verdict:string,avg_rir:?float}
     */
    public static function compare(array $hardestRirs, int $min, int $max): array
    {
        if (! $hardestRirs) {
            return ['verdict' => 'unknown', 'avg_rir' => null];
        }
        $avg = round(array_sum($hardestRirs) / count($hardestRirs), 1);
        $v = $avg < $min - self::TOLERANCE ? 'harder' : ($avg > $max + self::TOLERANCE ? 'easier' : 'in_range');

        return ['verdict' => $v, 'avg_rir' => $avg];
    }

    /** Validate and normalise user input; null when it is not a usable ramp. */
    public static function normalise(mixed $in): ?array
    {
        if (! is_array($in) || empty($in['start']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['start']) || empty($in['weeks']) || ! is_array($in['weeks']) || count($in['weeks']) > 8) {
            return null;
        }
        $weeks = [];
        foreach ($in['weeks'] as $w) {
            if (! is_array($w) || count($w) !== 2 || ! is_numeric($w[0]) || ! is_numeric($w[1])) {
                return null;
            }
            [$a, $b] = [(int) $w[0], (int) $w[1]];
            if ($a < 0 || $b > 5 || $a > $b) {
                return null;
            }
            $weeks[] = [$a, $b];
        }

        return ['start' => (string) $in['start'], 'weeks' => $weeks];
    }

    private static function monday(string $ymd): string
    {
        return date('Y-m-d', strtotime($ymd . ' -' . ((int) date('N', strtotime($ymd)) - 1) . ' days'));
    }
}
