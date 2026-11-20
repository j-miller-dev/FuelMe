<?php
declare(strict_types=1);

namespace App\Domain\Capture;

use App\Domain\Foods\ServingResolver;

/**
 * How many grams (or mL for liquids) of THIS food did the person mean? Order (BLUEPRINT 6.1 step 4, with the project rule
 * "never guess a weight for counts or cups of solids"):
 *   1. an explicit weight/volume,  2. the food's own stored servings (1 egg, 1 slice, 1 can),  3. cup/tbsp/tsp for liquids,
 *   4. the grams you usually have of it (alias),  5. the model's estimate (marked estimated),  6. a default you must confirm.
 * Pure.
 */
final class PortionResolver
{
    /**
     * @param array<string,mixed> $item a cleaned parse item
     * @param array<string,mixed> $hit a FoodSearch result (is_liquid, servings, last_grams)
     * @return array{grams:float, estimated:bool, needs_amount:bool, source:string, note:?string}
     */
    public static function resolve(array $item, array $hit, ?float $aliasGrams = null): array
    {
        $qty = $item['quantity'] ?? null;
        $unit = $item['unit'] ?? null;
        $servings = $hit['servings'] ?? [];
        $liquid = (bool) ($hit['is_liquid'] ?? false);

        if (($unit === 'g' || $unit === 'ml') && $qty !== null) {
            return self::out((float) $qty, false, false, 'stated');
        }
        if ($qty !== null) {
            $r = ServingResolver::resolve((float) $qty, in_array($unit, ['cup', 'tbsp', 'tsp'], true) ? $unit : null, $liquid, null, $servings);
            if ($r['grams'] !== null) {
                return self::out((float) $r['grams'], false, false, in_array($unit, ['cup', 'tbsp', 'tsp'], true) ? 'measure' : 'serving');
            }
        }
        if ($qty === null && $unit === null && $aliasGrams !== null) {
            return self::out($aliasGrams, false, false, 'usual');
        }
        if (($item['grams_estimate'] ?? null) !== null) {
            return self::out((float) $item['grams_estimate'], true, false, 'estimate');
        }
        $default = $hit['last_grams'] ?? ($servings[0]['grams'] ?? 100.0);

        return self::out((float) $default, true, true, 'default', 'Amount not known for this food. Check it.');
    }

    private static function out(float $grams, bool $estimated, bool $needs, string $source, ?string $note = null): array
    {
        $note ??= null;
        if ($grams > ParseValidator::MAX_GRAMS) {
            $grams = ParseValidator::MAX_GRAMS;
            $note = 'Capped at 3 kg. Check the amount.';
        }

        return ['grams' => round($grams, 1), 'estimated' => $estimated, 'needs_amount' => $needs, 'source' => $source, 'note' => $note];
    }
}
