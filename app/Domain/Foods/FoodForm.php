<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/** Turns what a person types from a nutrition panel (per 100 g, or per serve) into per-100 values, with sanity checks. Pure. */
final class FoodForm
{
    /**
     * @param array{basis:string, serving_grams?:?float, kcal?:?float, protein:float, carbs:float, fat:float, fibre?:?float, sugar?:?float, sat_fat?:?float, sodium_mg?:?float} $in
     * @return array{per100:array<string,?float>, needs_check:bool, warnings:list<string>}
     * @throws \InvalidArgumentException with a message fit to show the user
     */
    public static function toPer100(array $in): array
    {
        $per = ($in['basis'] ?? 'per100') === 'serving';
        $grams = (float) ($in['serving_grams'] ?? 0);
        if ($per && $grams <= 0) {
            throw new \InvalidArgumentException('Enter the serving size in grams to convert from per-serve values.');
        }
        $k = $per ? 100.0 / $grams : 1.0;
        $c = fn (?float $v, int $dp = 2) => $v === null ? null : round($v * $k, $dp);

        $p = $c((float) $in['protein']);
        $cb = $c((float) $in['carbs']);
        $f = $c((float) $in['fat']);
        $kcalIn = isset($in['kcal']) && (float) $in['kcal'] > 0 ? (float) $in['kcal'] : null;
        $kcal = $kcalIn !== null ? $c($kcalIn) : round(4 * $p + 4 * $cb + 9 * $f, 2);

        foreach (['Protein' => $p, 'Carbs' => $cb, 'Fat' => $f] as $name => $g) {
            if ($g < 0 || $g > 100) {
                throw new \InvalidArgumentException("$name works out to " . round($g, 1) . ' g per 100 g, which is not possible. Check the amounts and the serving size.');
            }
        }
        if ($p + $cb + $f > 100.5) {
            throw new \InvalidArgumentException('Protein + carbs + fat add up to more than 100 g per 100 g. Check the amounts and the serving size.');
        }
        if ($kcal <= 0) {
            throw new \InvalidArgumentException('Enter calories, or at least one macro.');
        }
        if ($kcal > 900) {
            throw new \InvalidArgumentException('That is more than 900 kcal per 100 g, which is not possible (pure fat is about 900). Check the serving size.');
        }

        $secondary = [];
        foreach (['fibre' => 'fibre', 'sugar' => 'sugar', 'sat_fat' => 'sat_fat'] as $in_key => $out) {
            $v = $c($in[$in_key] ?? null);
            if ($v !== null && ($v < 0 || $v > 100)) {
                throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $in_key)) . ' is outside 0-100 g per 100 g. Check the amount.');
            }
            $secondary["per100_$out"] = $v;
        }
        $na = $c($in['sodium_mg'] ?? null, 1);
        if ($na !== null && ($na < 0 || $na > 100000)) {
            throw new \InvalidArgumentException('Sodium is outside the possible range. Check the amount (it is in mg).');
        }

        $warnings = [];
        $atwater = 4 * $p + 4 * $cb + 9 * $f;
        $needsCheck = false;
        if ($kcalIn !== null && $kcal > 50 && abs($kcal - $atwater) / $kcal > 0.20) {
            $needsCheck = true;
            $warnings[] = 'The calories (' . round($kcal) . ') are more than 20% away from what the macros add up to (' . round($atwater) . '). Fibre or alcohol can explain this; otherwise check the label.';
        }

        return [
            'per100' => ['per100_kcal' => $kcal, 'per100_protein' => $p, 'per100_carbs' => $cb, 'per100_fat' => $f] + $secondary + ['per100_sodium' => $na],
            'needs_check' => $needsCheck, 'warnings' => $warnings,
        ];
    }
}
