<?php
declare(strict_types=1);

namespace App\Domain\Recovery;

/**
 * Compares your last 7 days of a daily reading (HRV, resting heart rate) with YOUR OWN 60-day baseline before that, the way athlete-monitoring
 * studies do. "Usual range" = baseline mean +/- 0.5 x its standard deviation (the "smallest worthwhile change"; an ASSUMPTION, not a clinical
 * threshold). HRV is averaged on a log scale because its spread is proportional. Needs enough readings or it says it does not know. Pure PHP.
 */
final class Baseline
{
    public const RECENT_DAYS = 7;
    public const BASE_DAYS = 60;
    public const MIN_RECENT = 4;
    public const MIN_BASE = 14;
    public const SWC = 0.5;

    /**
     * @param array<string,float> $series date => value
     * @param float $minSd floor for the spread, in the same units as the (possibly log) values, so a very steady baseline cannot make a normal wobble look unusual
     * @return array<string,mixed> status: unknown | below | within | above
     */
    public static function compare(array $series, string $today, bool $log, float $minSd): array
    {
        $recent = $base = [];
        for ($i = 0; $i < self::RECENT_DAYS + self::BASE_DAYS; $i++) {
            $v = $series[date('Y-m-d', strtotime($today . " -{$i} days"))] ?? null;
            if ($v === null || $v <= 0) {
                continue;
            }
            $x = $log ? log((float) $v) : (float) $v;
            $i < self::RECENT_DAYS ? $recent[] = $x : $base[] = $x;
        }
        $last = $series[$today] ?? null;
        if (count($recent) < self::MIN_RECENT) {
            return ['status' => 'unknown', 'reason' => sprintf('Needs at least %d readings in the last %d days (has %d).', self::MIN_RECENT, self::RECENT_DAYS, count($recent)), 'last' => $last, 'nRecent' => count($recent), 'nBase' => count($base)];
        }
        if (count($base) < self::MIN_BASE) {
            return ['status' => 'unknown', 'reason' => sprintf('Needs about %d days of history to know what is usual for you (has %d).', self::MIN_BASE, count($base)), 'last' => $last, 'nRecent' => count($recent), 'nBase' => count($base)];
        }
        $mean = array_sum($base) / count($base);
        $var = array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $base)) / (count($base) - 1);
        $sd = max(sqrt($var), $minSd);
        $r = array_sum($recent) / count($recent);
        $lo = $mean - self::SWC * $sd;
        $hi = $mean + self::SWC * $sd;
        $back = fn (float $x): float => $log ? exp($x) : $x;

        return [
            'status' => $r < $lo ? 'below' : ($r > $hi ? 'above' : 'within'),
            'recent' => round($back($r), 1), 'baseline' => round($back($mean), 1), 'low' => round($back($lo), 1), 'high' => round($back($hi), 1),
            'pct' => round(($back($r) / $back($mean) - 1) * 100, 1), 'last' => $last, 'nRecent' => count($recent), 'nBase' => count($base),
        ];
    }
}
