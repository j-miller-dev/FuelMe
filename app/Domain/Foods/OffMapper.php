<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/**
 * Maps one Open Food Facts export row (column name => value) to a foods row, or rejects it with a reason.
 * Pure. Rules from BLUEPRINT 5.2: reject missing macros, nonsense values, and rows whose kcal disagree with
 * 4P + 4C + 9F by more than 20% (when kcal > 50); flag softer disagreements as needs_check.
 */
final class OffMapper
{
    /** @return array{food:array<string,mixed>, serving:?array{label:string,grams:float}}|array{reject:string} */
    public static function map(array $r): array
    {
        $barcode = preg_replace('/\D/', '', (string) ($r['code'] ?? ''));
        if (strlen($barcode) < 8 || strlen($barcode) > 14) {
            return ['reject' => 'bad barcode'];
        }
        $name = trim((string) (($r['product_name'] ?? '') !== '' ? $r['product_name'] : ($r['generic_name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 200) {
            return ['reject' => 'no name'];
        }

        $kcal = self::num($r['energy-kcal_100g'] ?? null);
        if ($kcal === null) {
            $kj = self::num($r['energy_100g'] ?? ($r['energy-kj_100g'] ?? null));
            $kcal = $kj === null ? null : round($kj / AfcdMapper::KJ_PER_KCAL, 2);
        }
        $p = self::num($r['proteins_100g'] ?? null);
        $c = self::num($r['carbohydrates_100g'] ?? null);
        $f = self::num($r['fat_100g'] ?? null);
        if ($kcal === null || $p === null || $c === null || $f === null) {
            return ['reject' => 'missing macros'];
        }
        foreach ([$p, $c, $f] as $g) {
            if ($g < 0 || $g > 100) {
                return ['reject' => 'macro out of range'];
            }
        }
        if ($kcal < 0 || $kcal > 900 || $p + $c + $f > 100.5) {
            return ['reject' => 'nonsense energy or macros'];
        }
        $atwater = 4 * $p + 4 * $c + 9 * $f;
        $dev = $kcal > 50 ? abs($kcal - $atwater) / $kcal : 0.0;
        if ($kcal > 50 && $dev > 0.20) {
            return ['reject' => 'kcal disagrees with macros'];
        }

        // Secondary nutrients are user-typed in OFF and sometimes absurd (fibre 99999 g). An impossible value (outside
        // 0-100 g per 100 g) is dropped and the row kept; the macros that drive the plan were validated above.
        $fibre = self::within(self::num($r['fiber_100g'] ?? null));
        $sugar = self::within(self::num($r['sugars_100g'] ?? null));
        $sat = self::within(self::num($r['saturated-fat_100g'] ?? null));
        $sodiumG = self::within(self::num($r['sodium_100g'] ?? null));
        if ($sodiumG === null && ($salt = self::within(self::num($r['salt_100g'] ?? null))) !== null) {
            $sodiumG = $salt / 2.5; // salt = 2.5 x sodium
        }
        $sodiumMg = $sodiumG === null ? null : round($sodiumG * 1000, 1);

        // Softer disagreements are kept but flagged for the "check this" badge.
        $needsCheck = $dev > 0.10
            || ($sat !== null && $sat > $f + 0.5)
            || ($sugar !== null && $sugar > $c + 0.5);

        $serving = null;
        $qty = self::num($r['serving_quantity'] ?? null);
        $label = trim((string) ($r['serving_size'] ?? ''));
        if ($qty !== null && $qty > 0 && $qty <= 2000 && $label !== '') {
            $serving = ['label' => mb_substr($label, 0, 80), 'grams' => $qty];
        }

        $quality = 60 + ($serving ? 5 : 0) + ($fibre !== null ? 5 : 0) + ($sodiumMg !== null ? 5 : 0) - ($needsCheck ? 15 : 0);

        $brands = array_values(array_filter(array_map('trim', explode(',', (string) ($r['brands'] ?? '')))));
        $stores = array_values(array_filter(array_map('trim', explode(',', (string) ($r['stores'] ?? '')))));
        $cats = mb_substr(implode(', ', array_slice(array_filter(array_map('trim', explode(',', (string) ($r['categories_en'] ?? '')))), 0, 3)), 0, 160);
        $isLiquid = (bool) preg_match('/\d\s*(ml|cl|dl|l)\b/i', ($r['quantity'] ?? '') . ' ' . ($r['serving_size'] ?? ''));

        return [
            'food' => [
                'user_id' => null, 'source' => 'off', 'source_id' => $barcode, 'barcode' => $barcode,
                'name' => $name, 'brand' => $brands[0] ?? null, 'stores' => json_encode($stores),
                'category' => $cats !== '' ? $cats : null, 'state' => 'as_sold',
                'per100_kcal' => $kcal, 'per100_protein' => $p, 'per100_carbs' => $c, 'per100_fat' => $f,
                'per100_fibre' => $fibre, 'per100_sugar' => $sugar, 'per100_sat_fat' => $sat, 'per100_sodium' => $sodiumMg,
                'is_liquid' => $isLiquid, 'density_g_ml' => null,
                'quality' => max(0, min(100, $quality)), 'needs_check' => $needsCheck,
            ],
            'serving' => $serving,
        ];
    }

    private static function within(?float $g): ?float
    {
        return $g !== null && $g >= 0 && $g <= 100 ? $g : null;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '.', (string) $v);

        return is_numeric($v) ? (float) $v : null;
    }
}
