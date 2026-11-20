<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/** Rate of change of the Kalman TREND weight (never the raw scale, which is mostly water noise). Pure. */
final class TrendStats
{
    /**
     * @param list<array{date:string,trend:float}> $series chronological, one row per day
     * @return array{kgPerWeek:float,pctPerWeek:float,days:int,deltaKg:float}|null null when there are fewer than $minDays days to measure over
     */
    public static function slope(array $series, int $window = 14, int $minDays = 10, int $endOffset = 0): ?array
    {
        $n = count($series) - $endOffset;
        if ($n < 2) {
            return null;
        }
        $end = $series[$n - 1];
        $start = $series[max(0, $n - 1 - $window)];
        $days = (int) round((strtotime($end['date']) - strtotime($start['date'])) / 86400);
        if ($days < $minDays) {
            return null;
        }
        $delta = $end['trend'] - $start['trend'];
        $kgPerWeek = $delta / $days * 7;

        return ['kgPerWeek' => round($kgPerWeek, 3), 'pctPerWeek' => round($kgPerWeek / max($end['trend'], 1.0) * 100, 3), 'days' => $days, 'deltaKg' => round($delta, 2)];
    }
}
