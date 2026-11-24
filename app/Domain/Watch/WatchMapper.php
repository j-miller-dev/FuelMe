<?php
declare(strict_types=1);

namespace App\Domain\Watch;

/**
 * Turns Intervals.icu wellness and activity JSON into FUEL rows. Pure PHP. Every number is bounds-checked: a value outside what a body or
 * watch can plausibly produce is dropped (null), never clamped, so a glitch cannot show up as a real reading. Field names are taken from
 * community descriptions of the Intervals.icu API (restingHR, hrv, sleepSecs, steps, start_date_local ...) and read defensively: a missing or
 * differently named field just leaves that value empty.
 */
final class WatchMapper
{
    /** @return array{local_date:string,sleep_secs:?int,resting_hr:?int,hrv:?float,steps:?int}|null null when the day has none of the four */
    public static function wellness(array $w): ?array
    {
        $date = (string) ($w['id'] ?? $w['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $row = [
            'local_date' => $date,
            'sleep_secs' => self::int($w['sleepSecs'] ?? null, 3600, 18 * 3600),     // under 1 h is a nap or a glitch, over 18 h is a glitch
            'resting_hr' => self::int($w['restingHR'] ?? null, 25, 130),
            'hrv' => self::float($w['hrv'] ?? null, 5, 300),                          // units are whatever the watch reports (usually rMSSD, ms)
            'steps' => self::int($w['steps'] ?? null, 0, 100000),
        ];

        return $row['sleep_secs'] === null && $row['resting_hr'] === null && $row['hrv'] === null && $row['steps'] === null ? null : $row;
    }

    /** @return array{ext_id:string,local_date:string,start_local:string,name:string,type:?string,minutes:?int,distance_m:?int,moving_secs:?int,avg_hr:?int,max_hr:?int,load:?int,source:?string}|null */
    public static function activity(array $a): ?array
    {
        $id = (string) ($a['id'] ?? '');
        $start = (string) ($a['start_date_local'] ?? '');
        // Activities that only came from Strava have an id and a note but no data, so there is nothing to keep.
        if ($id === '' || ! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $start, $m)) {
            return null;
        }
        $secs = self::int($a['moving_time'] ?? $a['elapsed_time'] ?? null, 1, 24 * 3600);

        return [
            'ext_id' => mb_substr($id, 0, 40),
            'local_date' => $m[1],
            'start_local' => $m[1] . ' ' . $m[2],
            'name' => mb_substr(trim((string) ($a['name'] ?? '')) ?: 'Activity', 0, 120),
            'type' => isset($a['type']) ? mb_substr((string) $a['type'], 0, 40) : null,
            'minutes' => $secs === null ? null : (int) max(1, round($secs / 60)),
            'distance_m' => self::int($a['distance'] ?? null, 1, 1_000_000),          // metres, as Intervals.icu reports it; unreasonable values are dropped
            'moving_secs' => $secs,
            'avg_hr' => self::int($a['average_heartrate'] ?? null, 30, 230),
            'max_hr' => self::int($a['max_heartrate'] ?? null, 30, 230),
            'load' => self::int($a['icu_training_load'] ?? null, 0, 2000),
            'source' => isset($a['source']) ? mb_substr((string) $a['source'], 0, 30) : null,
        ];
    }

    private static function int(mixed $v, int $min, int $max): ?int
    {
        if (! is_numeric($v)) {
            return null;
        }
        $n = (int) round((float) $v);

        return $n >= $min && $n <= $max ? $n : null;
    }

    private static function float(mixed $v, float $min, float $max): ?float
    {
        if (! is_numeric($v)) {
            return null;
        }
        $n = round((float) $v, 1);

        return $n >= $min && $n <= $max ? $n : null;
    }
}
