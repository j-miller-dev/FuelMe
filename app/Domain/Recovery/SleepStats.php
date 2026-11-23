<?php
declare(strict_types=1);

namespace App\Domain\Recovery;

/**
 * Sleep against a target YOU can change (default 8 h, an ASSUMPTION: adults are advised 7-9 h and people who train hard often sit at the upper end).
 * "Performance" = hours slept as a share of the target, capped at 100%. "Debt" = the shortfall summed over the nights in the last 7 that have data.
 * Duration only: it says nothing about sleep quality or stages. Pure PHP.
 */
final class SleepStats
{
    public const SHORT_BELOW = 0.85;      // last night under 85% of target counts as short
    public const DEBT_FLAG_MIN = 240;     // 4 h of shortfall across the week counts as a flag
    public const MIN_NIGHTS_FOR_DEBT = 4;

    /**
     * @param array<string,int> $nights date (the morning you woke up) => seconds slept
     * @return array<string,mixed>
     */
    public static function compute(array $nights, string $today, int $targetMin): array
    {
        $lastSecs = $nights[$today] ?? null;
        $lastMin = $lastSecs ? (int) round($lastSecs / 60) : null;
        $mins = [];
        for ($i = 0; $i < 7; $i++) {
            $s = $nights[date('Y-m-d', strtotime($today . " -{$i} days"))] ?? null;
            if ($s) {
                $mins[] = (int) round($s / 60);
            }
        }
        $debt = (int) array_sum(array_map(fn ($m) => max(0, $targetMin - $m), $mins));

        return [
            'targetMin' => $targetMin, 'lastMin' => $lastMin,
            'performancePct' => $lastMin === null ? null : (int) min(100, round($lastMin / $targetMin * 100)),
            'short' => $lastMin !== null && $lastMin < self::SHORT_BELOW * $targetMin,
            'nights7' => count($mins), 'avg7Min' => $mins ? (int) round(array_sum($mins) / count($mins)) : null,
            'debt7Min' => $debt, 'debtFlag' => count($mins) >= self::MIN_NIGHTS_FOR_DEBT && $debt >= self::DEBT_FLAG_MIN,
        ];
    }
}
