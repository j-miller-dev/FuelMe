<?php
declare(strict_types=1);

namespace App\Domain\Shopping;

/**
 * Deterministic recipe -> shopping list aggregation (blueprint 5.6).
 * Ingredient: ['key'=>string,'name'=>string,'qty'=>?float,'unit'=>'g'|'ml'|'count'|null,'category'=>?string,'unit_g'=>?float]
 * Recipe:     ['id'=>string,'name'=>string,'yield'=>float,'ingredients'=>list<Ingredient>]
 */
final class ShoppingListBuilder
{
    /** @param array<string,string> $aliases normalised name => canonical key */
    public function __construct(private array $aisleByCategory, private array $aisleOrder, private array $aliases = []) {}

    /**
     * @param list<array{recipe:array,servings:float}> $selection
     * @param array<string,string> $state existing ingredient_key => need|have|bought (preserved on regeneration)
     * @param list<string> $alwaysHave keys auto-moved to Have
     * @return array{items:list<array>, byAisle:array<string,list<array>>}
     */
    public function build(array $selection, array $state = [], array $alwaysHave = []): array
    {
        $acc = [];
        foreach ($selection as $sel) {
            $r = $sel['recipe'];
            $scale = $sel['servings'] / max($r['yield'], 0.0001);
            foreach ($r['ingredients'] as $ing) {
                $key = $this->key($ing['key'] ?? $ing['name']);
                $acc[$key] ??= ['key' => $key, 'name' => $ing['name'], 'g' => 0.0, 'ml' => 0.0, 'count' => 0.0,
                    'staple' => false, 'category' => $ing['category'] ?? null, 'unit_g' => $ing['unit_g'] ?? null, 'recipes' => []];
                $a = &$acc[$key];
                if (($ing['qty'] ?? null) === null) {
                    $a['staple'] = true; // "to taste": listed once, never summed
                } else {
                    $q = $ing['qty'] * $scale;
                    $unit = $ing['unit'] ?? 'count';
                    if ($unit === 'count' && ($ing['unit_g'] ?? null)) { $a['g'] += $q * $ing['unit_g']; }
                    elseif ($unit === 'g') { $a['g'] += $q; }
                    elseif ($unit === 'ml') { $a['ml'] += $q; }
                    else { $a['count'] += $q; }
                    $a['unit_g'] ??= $ing['unit_g'] ?? null;
                }
                $a['recipes'][$r['id']] = $r['name'];
                unset($a);
            }
        }

        $items = [];
        foreach ($acc as $key => $a) {
            // convert leftover counts via average unit weight when we also hold grams
            if ($a['count'] > 0 && $a['g'] > 0 && $a['unit_g']) { $a['g'] += $a['count'] * $a['unit_g']; $a['count'] = 0.0; }
            [$qty, $unit] = match (true) {
                $a['g'] > 0 => [round($a['g'], 1), 'g'],
                $a['ml'] > 0 => [round($a['ml'], 1), 'ml'],
                $a['count'] > 0 => [round($a['count'], 2), 'count'],
                default => [null, null],
            };
            $status = $state[$key] ?? (in_array($key, $alwaysHave, true) ? 'have' : 'need');
            $items[] = ['key' => $key, 'name' => $a['name'], 'qty' => $qty, 'unit' => $unit, 'staple' => $a['staple'],
                'aisle' => $this->aisle($a['category']), 'status' => $status, 'recipes' => array_values($a['recipes'])];
        }
        usort($items, fn ($x, $y) => [array_search($x['aisle'], $this->aisleOrder), $x['name']]
            <=> [array_search($y['aisle'], $this->aisleOrder), $y['name']]);

        $byAisle = [];
        foreach ($items as $i) { $byAisle[$i['aisle']][] = $i; }
        return ['items' => $items, 'byAisle' => $byAisle];
    }

    private function key(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = $this->aliases[$n] ?? $n;
        return preg_replace('/(?<=[a-z])s$/', '', $n) ?? $n; // naive singularise
    }

    private function aisle(?string $cat): string
    {
        return $this->aisleByCategory[$cat ?? ''] ?? 'Other';
    }

    /** Pack-size rounding: 640 g with 500 g packs -> [2, 1000]. */
    public static function roundToPacks(float $needed, float $packSize): array
    {
        $n = (int) ceil($needed / $packSize - 1e-9);
        return [$n, $n * $packSize];
    }
}
