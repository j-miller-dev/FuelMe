<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * This week's training load against your usual. Last 7 days (including today) over the average 7-day total of the 28 days before that. Days in a
 * deload week are left out of "usual" so a planned easy week does not drag the baseline down. Rest days count as zero, so it assumes the sessions that
 * matter are being recorded somewhere. A comparison with yourself, never a prediction of injury. Pure PHP.
 */
final class LoadTrend
{
    public const USUAL_DAYS = 28;
    public const MIN_USUAL_DAYS = 14;

    /**
     * @param array<string,float|int> $daily date => load that day (missing = 0)
     * @param array<string,bool> $skip dates to leave out of the usual (deload weeks)
     * @return array{week:float,usual:?float,ratio:?float}
     */
    public static function ratio(array $daily, string $today, array $skip = []): array
    {
        $week = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $week += (float) ($daily[date('Y-m-d', strtotime($today . " -{$i} days"))] ?? 0);
        }
        $sum = 0.0;
        $n = 0;
        for ($i = 7; $i < 7 + self::USUAL_DAYS; $i++) {
            $d = date('Y-m-d', strtotime($today . " -{$i} days"));
            if (isset($skip[$d])) {
                continue;
            }
            $sum += (float) ($daily[$d] ?? 0);
            $n++;
        }
        $usual = $n >= self::MIN_USUAL_DAYS ? $sum / $n * 7 : null;

        return ['week' => round($week, 1), 'usual' => $usual === null ? null : round($usual, 1), 'ratio' => $usual !== null && $usual > 0 ? round($week / $usual, 2) : null];
    }
}
