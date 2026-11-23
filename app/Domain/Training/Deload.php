<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * Deload weeks. YOU label a week (Monday to Sunday) as a deload; the app then leaves it out of stall checks and load baselines so a planned easy week
 * is never read as a problem. It can also SUGGEST one when several independent signs line up, always as a suggestion. The thresholds are rules of
 * thumb (ASSUMPTIONS), not research results. Pure PHP.
 */
final class Deload
{
    public const STALLED_LIFTS = 2;
    public const BAD_DAYS = 3;
    public const LOAD_RATIO = 1.3;
    public const LONG_BLOCK_WEEKS = 5;
    public const TOO_SOON_WEEKS = 3;

    public static function mondayOf(string $date): string
    {
        return date('Y-m-d', strtotime('monday this week', strtotime($date)));
    }

    /** Whole weeks from the most recent labelled deload Monday to this week's Monday (0 = this week). Null if there is none on or before today. */
    public static function weeksSince(array $mondays, string $today): ?int
    {
        $thisMon = self::mondayOf($today);
        $past = array_filter($mondays, fn ($m) => $m <= $thisMon);
        if (! $past) {
            return null;
        }

        return (int) round((strtotime($thisMon) - strtotime(max($past))) / 604800);
    }

    /**
     * @return array{suggest:bool,reasons:list<string>}
     */
    public static function suggest(int $stalledLifts, int $badRecoveryDays7, ?float $loadRatio, ?int $weeksSince, bool $inDeload): array
    {
        if ($inDeload || ($weeksSince !== null && $weeksSince < self::TOO_SOON_WEEKS)) {
            return ['suggest' => false, 'reasons' => []];
        }
        $reasons = [];
        if ($stalledLifts >= self::STALLED_LIFTS) {
            $reasons[] = sprintf('%d lifts have stalled or slipped.', $stalledLifts);
        }
        if ($badRecoveryDays7 >= self::BAD_DAYS) {
            $reasons[] = sprintf('Recovery signals were poor on %d of the last 7 days.', $badRecoveryDays7);
        }
        if ($loadRatio !== null && $loadRatio >= self::LOAD_RATIO) {
            $reasons[] = sprintf('This week\'s load is %.1fx your usual.', $loadRatio);
        }
        $longBlock = $weeksSince !== null && $weeksSince >= self::LONG_BLOCK_WEEKS;
        $suggest = count($reasons) >= 2 || (count($reasons) >= 1 && $longBlock);
        if ($suggest && $longBlock) {
            $reasons[] = sprintf('It has been %d weeks since your last deload.', $weeksSince);
        }

        return ['suggest' => $suggest, 'reasons' => $suggest ? $reasons : []];
    }
}
