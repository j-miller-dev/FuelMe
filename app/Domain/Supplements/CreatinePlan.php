<?php

namespace App\Domain\Supplements;

/**
 * Where you are with creatine: an optional loading phase, then building up, then "stores should be full, continue at X g a day".
 * Rules of thumb from the ISSN position stands, NOT a measurement of your muscle (nothing here can see your stores):
 *  - loading = about 0.3 g per kg a day (capped 10-30 g) for 5-7 days, taken as several 5 g serves, then 3-5 g a day to maintain;
 *  - no loading = 3-5 g a day fills stores over about 3-4 weeks (taken here as 28 days with 26 or more of them logged).
 * Pure PHP. Dates are Y-m-d strings; $gramsByDate is total creatine logged per day.
 *
 * Plan shape: ['mode' => 'maintenance'|'loading', 'start' => 'Y-m-d', 'loading_days' => 5..7, 'maintenance_g' => 3..5]
 */
final class CreatinePlan
{
    public const SERVE_G = 5.0;
    public const SATURATION_DAYS = 28;
    public const SATURATION_MIN_DAYS = 26;
    public const QUALIFY_FRACTION = 0.8;       // a loading day counts at 80% of that day's target
    public const MIN_BUILD_DAY_G = 3.0;        // a build-up day counts at 3 g or more
    public const LOADING_GIVE_UP_DAYS = 14;    // a loading phase that has not finished by then falls back to the plain 28 days

    public static function defaults(string $today): array
    {
        return ['mode' => 'maintenance', 'start' => $today, 'loading_days' => 5, 'maintenance_g' => 5.0];
    }

    /** Validate user input; null when unusable. */
    public static function normalise(mixed $in): ?array
    {
        if (! is_array($in) || ! in_array($in['mode'] ?? null, ['maintenance', 'loading'], true)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['start'] ?? ''))
            || ! is_numeric($in['loading_days'] ?? null) || ! is_numeric($in['maintenance_g'] ?? null)) {
            return null;
        }
        $days = (int) $in['loading_days'];
        $m = (float) $in['maintenance_g'];
        if ($days < 5 || $days > 7 || $m < 3 || $m > 5) {
            return null;
        }

        return ['mode' => $in['mode'], 'start' => (string) $in['start'], 'loading_days' => $days, 'maintenance_g' => round($m * 2) / 2];
    }

    /** ISSN: about 0.3 g per kg per day. Rounded to a whole gram, capped so a data error cannot suggest 60 g. */
    public static function loadingTargetG(float $kg): int
    {
        return (int) max(10, min(30, round(0.3 * $kg)));
    }

    /**
     * @param array<string,float> $gramsByDate
     * @return array{phase:string,targetG:float,serveG:float,todayG:float,dayNumber:int,saturated:bool,continueG:float,progress:array{done:int,of:int,label:string},message:string,note:?string}
     */
    public static function status(array $plan, array $gramsByDate, float $kg, string $today): array
    {
        $start = $plan['start'];
        $maint = (float) $plan['maintenance_g'];
        $todayG = round((float) ($gramsByDate[$today] ?? 0.0), 2);
        $dayNumber = max(1, self::daysBetween($start, $today) + 1);
        $note = null;

        if ($start > $today) {
            return self::out('not_started', $maint, self::SERVE_G, $todayG, 0, false, $maint, 0, 1, 'days', 'Your plan starts on ' . $start . '.', null);
        }

        if ($plan['mode'] === 'loading' && $dayNumber <= self::LOADING_GIVE_UP_DAYS) {
            $target = (float) self::loadingTargetG($kg);
            $need = (int) $plan['loading_days'];
            $done = 0;
            for ($d = $start; $d <= $today; $d = self::addDays($d, 1)) {
                // today only counts once it is complete; an unfinished today is not a failed day
                if ($d === $today ? $todayG >= $target * self::QUALIFY_FRACTION : (($gramsByDate[$d] ?? 0.0) >= $target * self::QUALIFY_FRACTION)) {
                    $done++;
                }
            }
            if ($done >= $need) {
                return self::out('saturated', $maint, $maint, $todayG, $dayNumber, true, $maint, $need, $need, 'loading days',
                    "Loading is done. Your stores should be full, so drop to {$maint} g a day from now on.", 'Rough guide from sports-nutrition guidelines; it cannot measure your muscle.');
            }

            return self::out('loading', $target, self::SERVE_G, $todayG, $dayNumber, false, $maint, $done, $need, 'loading days',
                "Loading: about {$target} g today, split into serves of 5 g across the day. {$done} of {$need} loading days done.", null);
        }
        if ($plan['mode'] === 'loading') {
            $note = 'Loading did not finish within two weeks, so this is counting the plain 28-day build-up instead.';
        }

        // build-up: how many of the last 28 days had a real dose
        $from = self::addDays($today, -(self::SATURATION_DAYS - 1));
        $from = max($from, $start);
        $window = self::daysBetween($from, $today) + 1;
        $count = 0;
        for ($d = $from; $d <= $today; $d = self::addDays($d, 1)) {
            if (($gramsByDate[$d] ?? 0.0) >= self::MIN_BUILD_DAY_G) {
                $count++;
            }
        }
        $full = $window >= self::SATURATION_DAYS && $count >= self::SATURATION_MIN_DAYS;
        if ($full) {
            return self::out('saturated', $maint, $maint, $todayG, $dayNumber, true, $maint, $count, self::SATURATION_DAYS, 'days',
                "About four weeks of daily use is done, so your stores should be full. Continue at {$maint} g a day.", $note ?? 'Rough guide from sports-nutrition guidelines; it cannot measure your muscle.');
        }

        return self::out('building', $maint, $maint, $todayG, $dayNumber, false, $maint, $count, self::SATURATION_DAYS, 'days',
            "Building up: {$count} of " . self::SATURATION_DAYS . " days. Stores fill over about four weeks of daily use.", $note);
    }

    private static function out(string $phase, float $target, float $serve, float $todayG, int $day, bool $sat, float $cont, int $done, int $of, string $unit, string $message, ?string $note): array
    {
        return ['phase' => $phase, 'targetG' => $target, 'serveG' => $serve, 'todayG' => $todayG, 'dayNumber' => $day, 'saturated' => $sat, 'continueG' => $cont,
            'progress' => ['done' => $done, 'of' => $of, 'label' => $unit], 'message' => $message, 'note' => $note];
    }

    private static function daysBetween(string $a, string $b): int
    {
        return (int) floor((strtotime($b . ' 12:00 UTC') - strtotime($a . ' 12:00 UTC')) / 86400);
    }

    private static function addDays(string $d, int $n): string
    {
        return date('Y-m-d', strtotime($d . ' 12:00 UTC ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    }
}
