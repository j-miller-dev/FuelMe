<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/**
 * Recipe maths (BLUEPRINT 5.5). Pure.
 *
 * Ingredients are RAW weights. Cooking only changes weight (water), never the macros: the cooked weight is
 * raw x (1 - cookLoss), the macros are the sum of the ingredients, and a portion is 1/yield of both.
 */
final class MealMath
{
    /**
     * @param list<array{per100: array{kcal:?float,protein:?float,carbs:?float,fat:?float,fibre?:?float}|null, grams: float}> $items per100 null = the food is gone
     * @return array{raw_grams:float,cooked_grams:float,total:array{kcal:float,protein:float,carbs:float,fat:float,fibre:float},portion:array{grams:float,kcal:float,protein:float,carbs:float,fat:float,fibre:float},per100:array{kcal:float,protein:float,carbs:float,fat:float},incomplete:int}
     */
    public static function compute(array $items, float $yield, float $cookLoss): array
    {
        if ($yield <= 0) {
            throw new \InvalidArgumentException('A recipe must make at least a fraction of a portion.');
        }
        if ($cookLoss < 0 || $cookLoss >= 1) {
            throw new \InvalidArgumentException('Cooking loss must be from 0% to under 100%.');
        }

        $t = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0, 'fibre' => 0.0];
        $raw = 0.0;
        $incomplete = 0;
        foreach ($items as $it) {
            $raw += $it['grams'];
            $p = $it['per100'];
            if ($p === null || $p['kcal'] === null) {
                $incomplete++;   // never guess: it counts as zero and the UI says the total is understated
                continue;
            }
            $m = Portion::macros($p + ['fibre' => null], $it['grams']);
            foreach (['kcal', 'protein', 'carbs', 'fat'] as $k) {
                $t[$k] += $m[$k];
            }
            $t['fibre'] += $m['fibre'] ?? 0.0;
        }

        $cooked = $raw * (1 - $cookLoss);
        $per = fn (float $v) => round($v / $yield, 2);
        $per100 = fn (float $v) => $cooked > 0 ? round($v / $cooked * 100, 2) : 0.0;

        return [
            'raw_grams' => round($raw, 2),
            'cooked_grams' => round($cooked, 2),
            'total' => array_map(fn ($v) => round($v, 2), $t),
            'portion' => ['grams' => round($cooked / $yield, 2)] + array_map($per, $t),
            'per100' => ['kcal' => $per100($t['kcal']), 'protein' => $per100($t['protein']), 'carbs' => $per100($t['carbs']), 'fat' => $per100($t['fat'])],
            'incomplete' => $incomplete,
        ];
    }

    /** Portions for a typed weight: "250 g of the curry" with a 400 g portion = 0.625 portions. */
    public static function portionsForGrams(float $grams, float $portionGrams): float
    {
        if ($portionGrams <= 0) {
            throw new \InvalidArgumentException('This meal has no weight.');
        }

        return round($grams / $portionGrams, 4);
    }

    /** Portions left in a prep batch. Never negative. */
    public static function remaining(float $made, float $logged): float
    {
        return max(0.0, round($made - $logged, 4));
    }
}
