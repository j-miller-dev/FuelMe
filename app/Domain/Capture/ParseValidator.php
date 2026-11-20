<?php
declare(strict_types=1);

namespace App\Domain\Capture;

/**
 * Strict gate for whatever the LLM returns (BLUEPRINT 6.8). The transcript is data and the model's output is untrusted: only the
 * known fields survive, strings are trimmed and bounded, enums are checked, quantities are clamped. Nutrition keys are never copied,
 * so a model that "helpfully" adds calories changes nothing. Pure.
 */
final class ParseValidator
{
    public const UNITS = ['g', 'ml', 'piece', 'slice', 'cup', 'tbsp', 'tsp', 'serve', 'can', 'bar', 'handful', 'scoop'];
    private const STATES = ['as_sold', 'raw', 'cooked', 'dry'];
    private const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];
    public const MAX_ITEMS = 25;
    public const MAX_GRAMS = 3000.0;

    /**
     * @param mixed $raw the decoded tool input
     * @return array{items:list<array<string,mixed>>, unparsed:list<string>}
     * @throws \InvalidArgumentException when nothing usable is there (the caller falls back to basic mode)
     */
    public static function clean(mixed $raw): array
    {
        if (! is_array($raw) || ! isset($raw['items']) || ! is_array($raw['items'])) {
            throw new \InvalidArgumentException('The AI reply had no items.');
        }
        $items = [];
        foreach (array_slice(array_values($raw['items']), 0, self::MAX_ITEMS) as $it) {
            if (! is_array($it)) {
                continue;
            }
            $query = self::str($it['name_query'] ?? null, 120);
            if ($query === null) {
                continue;
            }
            $unit = is_string($it['unit'] ?? null) && in_array(strtolower($it['unit']), self::UNITS, true) ? strtolower($it['unit']) : null;
            $qty = self::num($it['quantity'] ?? null, 0.0001, 10000);
            $grams = self::num($it['grams_estimate'] ?? null, 0.1, 1000000);
            $notes = [];
            if ($grams !== null && $grams > self::MAX_GRAMS) {
                $grams = self::MAX_GRAMS;
                $notes[] = 'amount capped at 3 kg';
            }
            if ($unit === 'g' || $unit === 'ml') {
                if ($qty !== null && $qty > self::MAX_GRAMS) {
                    $qty = self::MAX_GRAMS;
                    $notes[] = 'amount capped at 3 kg';
                }
                $grams ??= $qty;   // an explicit weight is the grams
            }
            $amb = [];
            foreach ((array) ($it['ambiguity'] ?? []) as $a) {
                if (($s = self::str($a, 80)) !== null && count($amb) < 4) {
                    $amb[] = $s;
                }
            }
            $items[] = [
                'phrase' => self::str($it['phrase'] ?? null, 200) ?? $query, 'name_query' => $query,
                'brand' => self::str($it['brand'] ?? null, 60), 'store' => self::str($it['store'] ?? null, 30),
                'state' => is_string($it['state'] ?? null) && in_array($it['state'], self::STATES, true) ? $it['state'] : null,
                'quantity' => $qty, 'unit' => $unit, 'grams_estimate' => $grams,
                'meal_slot' => is_string($it['meal_slot'] ?? null) && in_array($it['meal_slot'], self::SLOTS, true) ? $it['meal_slot'] : null,
                'is_approximate' => (bool) ($it['is_approximate'] ?? false), 'ambiguity' => array_merge($amb, $notes),
                'components' => [],
            ];
        }
        if ($items === []) {
            throw new \InvalidArgumentException('The AI reply had no usable items.');
        }
        $unparsed = [];
        foreach (array_slice((array) ($raw['unparsed'] ?? []), 0, 10) as $u) {
            if (($s = self::str($u, 200)) !== null) {
                $unparsed[] = $s;
            }
        }

        return ['items' => $items, 'unparsed' => $unparsed];
    }

    private static function str(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags($v)) ?? '');

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function num(mixed $v, float $min, float $max): ?float
    {
        if (! is_int($v) && ! is_float($v) && ! (is_string($v) && is_numeric($v))) {
            return null;
        }
        $f = (float) $v;

        return is_finite($f) && $f >= $min ? min($f, $max) : null;
    }
}
