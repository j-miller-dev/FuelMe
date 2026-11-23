<?php
declare(strict_types=1);

namespace App\Domain\Watch;

/**
 * When two devices record the same session, keep ONE. Polar wins over COROS (the chest-strap Polar is the more accurate heart-rate source, and it is
 * only worn on the sessions that matter), then COROS over anything else. Two activities are "the same session" when they start within 20 minutes of
 * each other, or overlap by at least half of the shorter one. Nothing is deleted: the losers are reported so they can still be shown as "also recorded". Pure PHP.
 */
final class ActivityDedupe
{
    public const SAME_START_MIN = 20;
    public const OVERLAP_SHARE = 0.5;

    /** Lower = preferred. */
    public static function rank(?string $source): int
    {
        $s = strtoupper((string) $source);

        return match (true) {
            str_contains($s, 'POLAR') => 1,
            str_contains($s, 'COROS') => 2,
            default => 3,
        };
    }

    /**
     * @param list<array{id:int|string,start:int,minutes:?int,source:?string}> $acts start = unix seconds
     * @return array{keep:list<int|string>,duplicates:array<int|string,int|string>} duplicates: dropped id => the id kept instead
     */
    public static function resolve(array $acts): array
    {
        usort($acts, fn ($a, $b) => self::rank($a['source']) <=> self::rank($b['source']) ?: $a['start'] <=> $b['start']);
        $kept = [];
        $dups = [];
        foreach ($acts as $a) {
            $match = null;
            foreach ($kept as $k) {
                if (self::same($a, $k)) {
                    $match = $k;
                    break;
                }
            }
            if ($match) {
                $dups[$a['id']] = $match['id'];
            } else {
                $kept[] = $a;
            }
        }

        return ['keep' => array_column($kept, 'id'), 'duplicates' => $dups];
    }

    /** @param array{start:int,minutes:?int} $a @param array{start:int,minutes:?int} $b */
    public static function same(array $a, array $b): bool
    {
        if (abs($a['start'] - $b['start']) <= self::SAME_START_MIN * 60) {
            return true;
        }
        $aEnd = $a['start'] + max(1, (int) $a['minutes']) * 60;
        $bEnd = $b['start'] + max(1, (int) $b['minutes']) * 60;
        $overlap = min($aEnd, $bEnd) - max($a['start'], $b['start']);
        $shorter = min($aEnd - $a['start'], $bEnd - $b['start']);

        return $overlap > 0 && $overlap >= self::OVERLAP_SHARE * $shorter;
    }
}
