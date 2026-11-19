<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/**
 * Turns a parsed quantity ("150 g", "300 ml", "1 cup", "2") into grams for one food, or null when we cannot say.
 * Pure: the caller passes the food's density/servings. Australian metric measures: cup 250 mL, tablespoon 20 mL,
 * teaspoon 5 mL. Counts ("2 eggs") only resolve when a stored serving names the unit; we never guess a weight.
 */
final class ServingResolver
{
    private const ML = ['ml' => 1.0, 'l' => 1000.0, 'cup' => 250.0, 'tbsp' => 20.0, 'tsp' => 5.0];
    private const G = ['g' => 1.0, 'kg' => 1000.0, 'oz' => 28.3495];

    /**
     * @param list<array{label:string,grams:float}> $servings
     * @return array{grams:?float, label:string}
     */
    public static function resolve(?float $qty, ?string $unit, bool $isLiquid, ?float $densityGPerMl, array $servings): array
    {
        if ($qty === null) {
            return ['grams' => null, 'label' => ''];
        }
        if ($unit !== null && isset(self::G[$unit])) {
            return ['grams' => round($qty * self::G[$unit], 1), 'label' => self::fmt($qty) . " $unit"];
        }
        if ($unit !== null && isset(self::ML[$unit])) {
            $ml = $qty * self::ML[$unit];
            // Liquid rows are per 100 mL, so grams here means "amount in the food's own unit" (mL for liquids).
            if ($isLiquid) {
                return ['grams' => round($ml, 1), 'label' => self::fmt($qty) . " $unit"];
            }
            if ($densityGPerMl !== null && $densityGPerMl > 0) {
                return ['grams' => round($ml * $densityGPerMl, 1), 'label' => self::fmt($qty) . " $unit"];
            }
            // A "cup" of a solid food needs a stored serving that names a cup; do not invent one.
            foreach ($servings as $s) {
                $u = self::unitsIn($s['label']);
                if ($u !== null && $u['unit'] === $unit) {
                    return ['grams' => round($qty * $s['grams'] / $u['n'], 1), 'label' => self::fmt($qty) . " $unit"];
                }
            }
            return ['grams' => null, 'label' => self::fmt($qty) . " $unit"];
        }
        // A bare count ("2 eggs", "weetbix 2") or "slice": count units named by a stored serving. A serving of
        // "2 biscuits (31 g)" is 15.5 g per biscuit, so "2" biscuits is 31 g, not 2 servings. A label with only a weight
        // ("50 g") says nothing about what one unit is, so it never resolves.
        foreach ($servings as $s) {
            $u = self::unitsIn($s['label']);
            if ($u !== null && !in_array($u['unit'], ['cup', 'tbsp', 'tsp'], true)) {
                return ['grams' => round($qty * $s['grams'] / $u['n'], 1), 'label' => self::fmt($qty) . ' × ' . $u['unit']];
            }
        }
        return ['grams' => null, 'label' => self::fmt($qty)];
    }

    /**
     * What countable unit does a serving label describe? "2 biscuits (31 g)" -> 2 biscuit; "33 g (2 biscuits)" -> 2 biscuit;
     * "1 serving (100 g)" -> 1 serving; "medium banana" -> 1 (no digits means one); "50 g" or "250 ml" -> null.
     * @return array{n:float, unit:string}|null
     */
    public static function unitsIn(string $label): ?array
    {
        $label = mb_strtolower(trim($label));
        $isMeasure = fn (string $w) => in_array($w, ['g', 'gram', 'grams', 'kg', 'ml', 'l', 'litre', 'litres', 'oz', 'mg', 'kj', 'kcal', 'cal'], true);
        $norm = function (string $w): string {
            $w = ['cups' => 'cup', 'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp', 'teaspoon' => 'tsp', 'teaspoons' => 'tsp'][$w] ?? $w;

            return strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss') ? substr($w, 0, -1) : $w;
        };
        // number + word at the start, or inside a bracket
        foreach (['/^(\d+(?:[.,]\d+)?)\s*([a-z]+)/u', '/\((\d+(?:[.,]\d+)?)\s*([a-z]+)/u'] as $re) {
            if (preg_match($re, $label, $m) && !$isMeasure($m[2]) && (float) str_replace(',', '.', $m[1]) > 0) {
                return ['n' => (float) str_replace(',', '.', $m[1]), 'unit' => $norm($m[2])];
            }
        }
        if (!preg_match('/\d/', $label) && preg_match('/([a-z]{3,})/u', $label, $m) && !$isMeasure($m[1])) {
            return ['n' => 1.0, 'unit' => $norm(preg_split('/\s+/', $label)[count(preg_split('/\s+/', $label)) - 1])];
        }

        return null;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
