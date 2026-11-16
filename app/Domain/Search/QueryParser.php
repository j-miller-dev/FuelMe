<?php
declare(strict_types=1);

namespace App\Domain\Search;

/** Parses "150g chicken breast", "2 eggs", "1 cup rice", "300ml milk", "weetbix 2" into term + quantity. */
final class QueryParser
{
    private const UNITS = ['g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'l', 'cup' => 'cup', 'cups' => 'cup',
        'tbsp' => 'tbsp', 'tsp' => 'tsp', 'oz' => 'oz', 'slice' => 'slice', 'slices' => 'slice'];

    /** @return array{term:string, qty:?float, unit:?string} */
    public static function parse(string $q): array
    {
        $q = trim(preg_replace('/\s+/', ' ', mb_strtolower($q)));
        // leading: number, optional unit, then the food
        if (preg_match('/^(\d+(?:[.,]\d+)?|\d+\/\d+)\s*([a-z]+)?\s+(.+)$/u', $q, $m)) {
            $unit = $m[2] !== '' ? (self::UNITS[$m[2]] ?? null) : null;
            if ($m[2] === '' || $unit !== null) {
                return ['term' => $m[3], 'qty' => self::num($m[1]), 'unit' => $unit];
            }
            // number followed by a non-unit word: "2 eggs" -> count
            return ['term' => $m[2] . ' ' . $m[3], 'qty' => self::num($m[1]), 'unit' => null];
        }
        // glued "150g chicken"
        if (preg_match('/^(\d+(?:\.\d+)?)(g|kg|ml|l)\s+(.+)$/u', $q, $m)) {
            return ['term' => $m[3], 'qty' => (float) $m[1], 'unit' => self::UNITS[$m[2]]];
        }
        // trailing count "weetbix 2"
        if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)$/u', $q, $m)) {
            return ['term' => $m[1], 'qty' => (float) $m[2], 'unit' => null];
        }
        return ['term' => $q, 'qty' => null, 'unit' => null];
    }

    private static function num(string $s): float
    {
        if (str_contains($s, '/')) { [$a, $b] = explode('/', $s); return (float) $a / (float) $b; }
        return (float) str_replace(',', '.', $s);
    }
}
