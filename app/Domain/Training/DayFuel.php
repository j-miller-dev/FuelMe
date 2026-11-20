<?php
declare(strict_types=1);

namespace App\Domain\Training;

use App\Domain\Nutrition\PeriWorkout;

/**
 * Fuelling for ALL of a day's sessions together (BLUEPRINT 9.8: "plan both windows jointly"). Each session gets its pre/during/post from
 * PeriWorkout, with the NEXT session's timing and type feeding the post-session advice, and a note when one meal has to do double duty. Pure.
 */
final class DayFuel
{
    /** @return array{start:?int,end:?int} minutes since midnight */
    public static function window(array $s): array
    {
        if (empty($s['start'])) {
            return ['start' => null, 'end' => null];
        }
        [$h, $m] = array_map('intval', explode(':', $s['start']));
        $start = $h * 60 + $m;

        return ['start' => $start, 'end' => $start + (int) $s['minutes']];
    }

    /**
     * @param list<array{key:string,type:string,intensity:string,minutes:int,start:?string,met?:float}> $sessions planned and done, not skipped
     * @return list<array<string,mixed>> one entry per session in time order (sessions with no time keep their order, last)
     */
    public static function plan(float $kg, array $sessions, float $leadHours = 2.0): array
    {
        usort($sessions, fn ($a, $b) => (self::window($a)['start'] ?? 99999) <=> (self::window($b)['start'] ?? 99999));
        $out = [];
        foreach ($sessions as $i => $s) {
            $w = self::window($s);
            $next = $sessions[$i + 1] ?? null;
            $nw = $next ? self::window($next) : ['start' => null, 'end' => null];
            $gap = ($w['end'] !== null && $nw['start'] !== null) ? max(0.0, ($nw['start'] - $w['end']) / 60.0) : null;
            // Only sessions within 24 h matter; the next one on the same day is all we model.
            $peri = PeriWorkout::plan($kg, $s, $leadHours, $gap, $next ? ['type' => $next['type'], 'intensity' => $next['intensity'], 'minutes' => (int) $next['minutes']] : null);

            $prev = $out[$i - 1] ?? null;
            $note = null;
            if ($prev !== null && $prev['gapToNextHours'] !== null && $prev['gapToNextHours'] < 3.0) {
                $note = sprintf('Only %s between %s and %s: your meal after the first session is also your meal before this one. Use the post-session targets and keep fat and fibre low.',
                    self::hm($prev['gapToNextHours']), self::label($prev), self::label($s));
            }
            $out[] = ['key' => $s['key'], 'type' => $s['type'], 'intensity' => $s['intensity'], 'minutes' => (int) $s['minutes'], 'start' => $s['start'] ?? null,
                'endMin' => $w['end'], 'startMin' => $w['start'], 'gapToNextHours' => $gap, 'sharedMealNote' => $note] + $peri;
        }

        return $out;
    }

    private static function hm(float $h): string
    {
        $m = (int) round($h * 60);

        return $m >= 60 ? sprintf('%dh %02dm', intdiv($m, 60), $m % 60) : $m . ' min';
    }

    private static function label(array $s): string
    {
        return ($s['name'] ?? null) ?: str_replace('_', ' ', $s['type']);
    }
}
