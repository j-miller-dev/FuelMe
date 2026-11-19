<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/** Macro maths for a logged portion. Numbers come from the food database row, never from the client or an LLM. Pure. */
final class Portion
{
    /**
     * @param array{kcal:?float,protein:?float,carbs:?float,fat:?float,fibre?:?float} $per100 values per 100 g (or per 100 mL for liquids)
     * @return array{kcal:float,protein:float,carbs:float,fat:float,fibre:?float}
     */
    public static function macros(array $per100, float $grams): array
    {
        $f = $grams / 100.0;
        $r = fn (?float $v, int $dp = 2) => $v === null ? null : round($v * $f, $dp);

        return [
            'kcal' => $r($per100['kcal']) ?? 0.0, 'protein' => $r($per100['protein']) ?? 0.0,
            'carbs' => $r($per100['carbs']) ?? 0.0, 'fat' => $r($per100['fat']) ?? 0.0, 'fibre' => $r($per100['fibre'] ?? null),
        ];
    }

    /**
     * Scale an existing log's SNAPSHOT to a new weight. Used when you edit the grams of something already logged: the food row
     * may have changed since, and history must not be rewritten.
     * @param array{kcal:float,protein:float,carbs:float,fat:float,fibre:?float} $snapshot
     */
    public static function scale(array $snapshot, float $oldGrams, float $newGrams): array
    {
        if ($oldGrams <= 0) {
            throw new \InvalidArgumentException('Cannot scale from a zero weight.');
        }
        $f = $newGrams / $oldGrams;

        return array_map(fn ($v) => $v === null ? null : round($v * $f, 2), $snapshot);
    }

    /** Quick-add: kcal defaults to Atwater (4P + 4C + 9F) when only macros are given. */
    public static function quickKcal(?float $kcal, float $p, float $c, float $f): float
    {
        return $kcal !== null && $kcal > 0 ? $kcal : round(4 * $p + 4 * $c + 9 * $f, 2);
    }
}
