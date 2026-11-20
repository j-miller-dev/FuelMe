<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/** How complete the data is, which decides whether a check-in may change your target (BLUEPRINT 9.9). Pure. */
final class Adherence
{
    /** A day counts as logged once it holds at least this share of the day's target. Half-logged days would teach the filter a fake deficit. */
    public const LOGGED_AT = 0.40;

    /**
     * @param list<array{date:string,kcal:float,target:float}> $days completed days, oldest first (kcal 0 when nothing was logged)
     * @return list<array{date:string,kcal:?float,target:float}> kcal null on days that do not count as logged
     */
    public static function loggedOnly(array $days): array
    {
        return array_map(fn ($d) => ['date' => $d['date'], 'target' => $d['target'], 'kcal' => $d['target'] > 0 && $d['kcal'] >= self::LOGGED_AT * $d['target'] ? $d['kcal'] : null], $days);
    }

    /** @param list<array{kcal:?float}> $days */
    public static function loggedCount(array $days): int
    {
        return count(array_filter($days, fn ($d) => $d['kcal'] !== null));
    }
}
