<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/**
 * "Frequent now": ranks the foods you log most, favouring ones you usually eat around this time of day and recently.
 * Each past log scores exp(-days/30) (a month half-life-ish), multiplied by 1 + 1.5 x closeness, where closeness falls
 * linearly from 1 (same hour) to 0 (3+ hours apart, measured around the clock). Pure PHP.
 */
final class Frequent
{
    /**
     * @param list<array{food_id:int,hour:int,days_ago:float}> $logs
     * @return list<array{food_id:int,score:float}> best first; ties broken by food id for a stable order
     */
    public static function rank(array $logs, int $nowHour, int $limit = 8): array
    {
        $score = [];
        foreach ($logs as $l) {
            $d = abs($l['hour'] - $nowHour);
            $d = min($d, 24 - $d);
            $closeness = max(0.0, 1.0 - $d / 3.0);
            $score[$l['food_id']] = ($score[$l['food_id']] ?? 0.0) + exp(-max(0.0, $l['days_ago']) / 30.0) * (1.0 + 1.5 * $closeness);
        }
        $out = array_map(fn ($id, $s) => ['food_id' => $id, 'score' => round($s, 4)], array_keys($score), $score);
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['food_id'] <=> $b['food_id']);

        return array_slice($out, 0, $limit);
    }

    /** Which meal slot is it around this hour? (used for the default slot in the Add sheet) */
    public static function slotForHour(int $hour): string
    {
        return match (true) {
            $hour >= 4 && $hour < 10 => 'breakfast',
            $hour >= 10 && $hour < 15 => 'lunch',
            $hour >= 15 && $hour < 21 => 'dinner',
            default => 'snack',
        };
    }
}
