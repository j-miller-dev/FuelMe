<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/**
 * Which "day" does a moment belong to? The user's local day, rolling over at a configurable hour (default 03:00),
 * so a snack at 01:30 on Tuesday still counts toward Monday (BLUEPRINT section 3). Pure PHP.
 */
final class LocalDay
{
    public static function of(\DateTimeInterface $moment, string $tz, int $rolloverHour = 3): string
    {
        $local = \DateTimeImmutable::createFromInterface($moment)->setTimezone(new \DateTimeZone($tz));

        return $local->modify('-' . max(0, min(12, $rolloverHour)) . ' hours')->format('Y-m-d');
    }

    /** Lower-case weekday key ("mon".."sun") of a Y-m-d date, matching the plan's day keys. */
    public static function dayKey(string $ymd): string
    {
        return strtolower((new \DateTimeImmutable($ymd . ' 12:00:00', new \DateTimeZone('UTC')))->format('D'));
    }
}
