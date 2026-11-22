<?php

namespace App\Domain\Training;

use App\Domain\Foods\LocalDay;

/** Turns a Hevy API workout into the flat shape FUEL stores. Pure PHP; tolerant of missing fields because the API may add or omit them. */
final class HevyMapper
{
    /**
     * @param array<string,mixed> $w one workout from the API
     * @return array{hevy_id:string,title:string,start_at:string,end_at:?string,local_date:string,minutes:?int,sets:list<array<string,mixed>>}|null null when it has no id or start time
     */
    public static function workout(array $w, string $tz, int $rolloverHour = 3): ?array
    {
        $id = (string) ($w['id'] ?? '');
        $start = (string) ($w['start_time'] ?? '');
        $ts = $start !== '' ? strtotime($start) : false;
        if ($id === '' || $ts === false) {
            return null;
        }
        $end = isset($w['end_time']) && strtotime((string) $w['end_time']) !== false ? (string) $w['end_time'] : null;
        $minutes = $end !== null && strtotime($end) > $ts ? (int) round((strtotime($end) - $ts) / 60) : null;
        $sets = [];
        foreach ((array) ($w['exercises'] ?? []) as $ei => $ex) {
            foreach ((array) ($ex['sets'] ?? []) as $si => $s) {
                $type = (string) ($s['type'] ?? 'normal');
                $sets[] = [
                    'exercise_index' => (int) ($ex['index'] ?? $ei), 'template_id' => (string) ($ex['exercise_template_id'] ?? ''), 'exercise_title' => mb_substr((string) ($ex['title'] ?? 'Exercise'), 0, 120),
                    'set_index' => (int) ($s['index'] ?? $si), 'type' => in_array($type, ['warmup', 'normal', 'failure', 'dropset'], true) ? $type : 'normal',
                    'weight_kg' => isset($s['weight_kg']) && is_numeric($s['weight_kg']) ? (float) $s['weight_kg'] : null, 'reps' => isset($s['reps']) && is_numeric($s['reps']) ? (int) $s['reps'] : null,
                    'rpe' => isset($s['rpe']) && is_numeric($s['rpe']) ? (float) $s['rpe'] : null, 'duration_seconds' => isset($s['duration_seconds']) && is_numeric($s['duration_seconds']) ? (int) $s['duration_seconds'] : null,
                    'distance_m' => isset($s['distance_meters']) && is_numeric($s['distance_meters']) ? (int) $s['distance_meters'] : null,
                ];
            }
        }

        return ['hevy_id' => $id, 'title' => mb_substr((string) ($w['title'] ?? 'Workout'), 0, 120), 'start_at' => gmdate('Y-m-d H:i:s', $ts), 'end_at' => $end !== null ? gmdate('Y-m-d H:i:s', strtotime($end)) : null,
            'local_date' => LocalDay::of(new \DateTimeImmutable('@' . $ts), $tz, $rolloverHour), 'minutes' => $minutes, 'sets' => $sets];
    }

    /** @param array<string,mixed> $t @return array{template_id:string,title:string,muscle:?string,secondary:list<string>}|null */
    public static function template(array $t): ?array
    {
        $id = (string) ($t['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return ['template_id' => $id, 'title' => mb_substr((string) ($t['title'] ?? 'Exercise'), 0, 120), 'muscle' => isset($t['primary_muscle_group']) ? (string) $t['primary_muscle_group'] : null,
            'secondary' => array_values(array_map('strval', (array) ($t['secondary_muscle_groups'] ?? [])))];
    }
}
