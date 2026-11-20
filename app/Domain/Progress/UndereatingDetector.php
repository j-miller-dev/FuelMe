<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * The undereating nudge (BLUEPRINT 9.9): intake under 90% of target on at least 4 of the last 7 LOGGED days. Unlogged days are not
 * counted either way, so forgetting to log never looks like undereating. Pure.
 */
final class UndereatingDetector
{
    public const BELOW = 0.90;
    public const MIN_LOW_DAYS = 4;
    public const WINDOW = 7;

    /**
     * @param list<array{date:string,kcal:?float,target:float,protein:float,proteinTarget:float}> $days oldest first
     * @return array{flag:bool,lowDays:int,loggedDays:int,avgPct:float,gapKcal:float,gapProtein:float}
     */
    public static function detect(array $days): array
    {
        $logged = array_values(array_filter($days, fn ($d) => $d['kcal'] !== null));
        $last = array_slice($logged, -self::WINDOW);
        $low = array_values(array_filter($last, fn ($d) => $d['kcal'] < self::BELOW * $d['target']));
        $n = count($last);
        $avgPct = $n ? array_sum(array_map(fn ($d) => $d['kcal'] / $d['target'], $last)) / $n * 100 : 0.0;
        $gapK = $n ? array_sum(array_map(fn ($d) => max(0.0, $d['target'] - $d['kcal']), $last)) / $n : 0.0;
        $gapP = $n ? array_sum(array_map(fn ($d) => max(0.0, $d['proteinTarget'] - $d['protein']), $last)) / $n : 0.0;

        return ['flag' => count($low) >= self::MIN_LOW_DAYS, 'lowDays' => count($low), 'loggedDays' => $n, 'avgPct' => round($avgPct, 1), 'gapKcal' => round($gapK), 'gapProtein' => round($gapP)];
    }
}
