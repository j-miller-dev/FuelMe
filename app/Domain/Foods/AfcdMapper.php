<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/** Pure mapping helpers for AFCD Release 3 rows (no framework, no database). */
final class AfcdMapper
{
    public const KJ_PER_KCAL = 4.184;

    // The sheet has two energy columns. "with dietary fibre, equated" is the one that matches how Australian
    // labels and the NIP report energy (fibre counted at 8 kJ/g), so we use it consistently for every food.
    public const COL_ENERGY = 'energy with dietary fibre, equated (kj)';
    public const COL_PROTEIN = 'protein (g)';
    public const COL_FAT = 'fat, total (g)';
    public const COL_FIBRE = 'total dietary fibre (g)';
    public const COL_SUGAR = 'total sugars (g)';
    public const COL_SAT_FAT = 'total saturated fatty acids, equated (g)';
    public const COL_SODIUM = 'sodium (na) (mg)';
    // Available carbohydrate: prefer the figure that includes sugar alcohols (it is the energy-bearing carbohydrate),
    // fall back to the one without.
    public const COL_CARBS = ['available carbohydrate, with sugar alcohols (g)', 'available carbohydrate, without sugar alcohols (g)'];

    /** Header text from the spreadsheet -> lowercase, single-spaced, so "Protein \n(g)" matches "protein (g)". */
    public static function normaliseHeader(?string $h): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $h)));
    }

    public static function kjToKcal(float|int|string|null $kj): ?float
    {
        if ($kj === null || $kj === '' || !is_numeric($kj)) {
            return null;
        }
        return round((float) $kj / self::KJ_PER_KCAL, 2);
    }

    /** raw | cooked | dry | null, from the food name (AFCD encodes preparation in the name). */
    public static function state(string $name): ?string
    {
        $n = mb_strtolower($name);
        if (preg_match('/\b(cooked|boiled|fried|stir-fried|baked|roasted|grilled|steamed|stewed|microwaved|braised|poached|barbecued|simmered|toasted)\b/u', $n)) {
            return 'cooked';
        }
        if (preg_match('/\braw\b/u', $n)) {
            return 'raw';
        }
        if (preg_match('/\b(dry|dried|uncooked|powder)\b/u', $n)) {
            return 'dry';
        }
        return null;
    }

    /** 0-100 trust score: analysed data beats borrowed/imputed. Feeds the 10% data-quality term in ranking. */
    public static function quality(?string $derivation): int
    {
        $d = mb_strtolower((string) $derivation);
        return match (true) {
            str_contains($d, 'analys') => 90,
            str_contains($d, 'calculat'), str_contains($d, 'recipe') => 80,
            str_contains($d, 'borrow') => 70,
            str_contains($d, 'imput') => 60,
            default => 70,
        };
    }

    /** "Vitamin C (mg)" -> "vitamin_c_mg": a stable key for the long-tail nutrient table. */
    public static function nutrientKey(string $normalisedHeader): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', $normalisedHeader), '_');
    }

    public static function num(mixed $v): ?float
    {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
    }
}
