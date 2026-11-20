<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\DB;

/**
 * Persists shopping lists (BLUEPRINT 5.6). The maths is ShoppingListBuilder (pure); this class feeds it from the meals tables and
 * writes the result back WITHOUT losing tick states or manual items. A list only changes when you change its recipes/servings or
 * confirm a refresh: editing a meal elsewhere never rewrites a list mid-shop.
 */
class ShoppingStore
{
    private ?array $aisles = null;

    private function aisles(): array
    {
        return $this->aisles ??= json_decode((string) file_get_contents(base_path('data/aisles.json')), true);
    }

    private function mapper(): AisleMapper
    {
        return AisleMapper::fromFile(base_path('data/aisle_keywords.json'));
    }

    public function own(int $userId, int $listId): object
    {
        $l = DB::table('shopping_lists')->where('id', $listId)->where('user_id', $userId)->first();
        abort_unless($l, 404);

        return $l;
    }

    /** @param list<array{meal_id:int,servings:float}> $recipes */
    public function create(int $userId, string $name, array $recipes): int
    {
        return DB::transaction(function () use ($userId, $name, $recipes) {
            $id = DB::table('shopping_lists')->insertGetId(['user_id' => $userId, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->setRecipes($userId, $id, $recipes);

            return $id;
        });
    }

    /** Replace the recipe selection (add/remove/change servings), then rebuild the list keeping ticks and manual items. */
    public function setRecipes(int $userId, int $listId, array $recipes): void
    {
        DB::transaction(function () use ($userId, $listId, $recipes) {
            DB::table('shopping_list_recipes')->where('list_id', $listId)->delete();
            foreach ($recipes as $r) {
                $m = DB::table('meals')->where('id', $r['meal_id'])->where('user_id', $userId)->first();
                abort_unless($m, 404);
                DB::table('shopping_list_recipes')->insert(['list_id' => $listId, 'meal_id' => $m->id, 'meal_name' => $m->name, 'servings' => $r['servings'],
                    'yield_portions' => $m->yield_portions, 'meal_stamp' => $m->updated_at]);
            }
            $this->regenerate($userId, $listId);
        });
    }

    /** Rebuild items from the CURRENT meals and the stored servings. Preserves need/have/bought, manual items and pantry notes. */
    public function regenerate(int $userId, int $listId): void
    {
        DB::transaction(function () use ($userId, $listId) {
            $order = $this->aisles()['order'];
            $builder = new ShoppingListBuilder(array_combine($order, $order), $order);
            $mapper = $this->mapper();

            $selection = [];
            foreach (DB::table('shopping_list_recipes')->where('list_id', $listId)->get() as $r) {
                $meal = $r->meal_id ? DB::table('meals')->where('id', $r->meal_id)->first() : null;
                if (! $meal) {
                    continue;   // the recipe was deleted: it contributes nothing and the screen says so
                }
                $ings = DB::table('meal_items')->leftJoin('foods', 'foods.id', '=', 'meal_items.food_id')->where('meal_id', $meal->id)->orderBy('sort')
                    ->get(['meal_items.food_id', 'meal_items.name', 'meal_items.grams', 'foods.is_liquid', 'foods.category'])
                    ->map(fn ($i) => ['key' => $i->food_id ? 'food:' . $i->food_id : $i->name, 'name' => $i->name, 'qty' => (float) $i->grams,
                        'unit' => $i->is_liquid ? 'ml' : 'g', 'category' => $mapper->aisle($i->name, $i->category)])->all();
                // Scale off the yield the recipe has NOW; the stored yield only drives the "changed" banner.
                $selection[] = ['recipe' => ['id' => (string) $meal->id, 'name' => $meal->name, 'yield' => (float) $meal->yield_portions, 'ingredients' => $ings], 'servings' => (float) $r->servings];
                DB::table('shopping_list_recipes')->where('list_id', $listId)->where('meal_id', $meal->id)->update(['meal_stamp' => $meal->updated_at, 'yield_portions' => $meal->yield_portions, 'meal_name' => $meal->name]);
            }

            $existing = DB::table('shopping_list_items')->where('list_id', $listId)->where('is_manual', false)->get()->keyBy('ingredient_key');
            $state = $existing->map(fn ($i) => $i->state)->all();
            $pantry = DB::table('pantry_items')->where('user_id', $userId)->get();
            $staples = $pantry->where('is_staple', true)->pluck('ingredient_key')->all();
            $qtys = $pantry->whereNotNull('qty')->mapWithKeys(fn ($p) => [$p->ingredient_key => ['qty' => (float) $p->qty, 'unit' => $p->unit]])->all();

            $built = $builder->build($selection, $state, $staples, $qtys);

            $keep = [];
            foreach ($built['items'] as $it) {
                $keep[] = $it['key'];
                $row = ['label' => $it['name'], 'qty' => $it['qty'], 'unit' => $it['unit'], 'have_qty' => $it['have_qty'], 'aisle' => $it['aisle'], 'state' => $it['status'],
                    'source_meals' => json_encode($it['recipes']), 'food_id' => str_starts_with($it['key'], 'food:') ? (int) substr($it['key'], 5) : null, 'updated_at' => now()];
                if ($existing->has($it['key'])) {
                    DB::table('shopping_list_items')->where('id', $existing[$it['key']]->id)->update($row);
                } else {
                    DB::table('shopping_list_items')->insert($row + ['list_id' => $listId, 'ingredient_key' => $it['key'], 'is_manual' => false, 'created_at' => now()]);
                }
            }
            DB::table('shopping_list_items')->where('list_id', $listId)->where('is_manual', false)->whereNotIn('ingredient_key', $keep ?: [''])->delete();
            DB::table('shopping_lists')->where('id', $listId)->update(['updated_at' => now()]);
        });
    }

    /** Add a hand-typed item ("toilet paper"). A client uuid makes a replayed offline add a no-op. */
    public function addManual(int $userId, int $listId, string $label, ?float $qty, ?string $unit, ?string $aisle, ?string $uuid): void
    {
        if ($uuid && DB::table('shopping_list_items')->where('list_id', $listId)->where('client_uuid', $uuid)->exists()) {
            return;
        }
        DB::table('shopping_list_items')->insert([
            'list_id' => $listId, 'ingredient_key' => 'manual:' . ($uuid ?: bin2hex(random_bytes(8))), 'label' => trim($label), 'qty' => $qty, 'unit' => $unit,
            'aisle' => $aisle && in_array($aisle, $this->aisles()['order'], true) ? $aisle : $this->mapper()->aisle($label), 'state' => 'need', 'source_meals' => '[]',
            'is_manual' => true, 'client_uuid' => $uuid, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Tick / untick / move to Have. Setting the same state twice is harmless, which is what makes offline replays safe. */
    public function setState(int $listId, int $itemId, string $state): void
    {
        DB::table('shopping_list_items')->where('id', $itemId)->where('list_id', $listId)->update(['state' => $state, 'updated_at' => now()]);
    }

    /** "I always have this" (staple) and "I have ~200 g" both live in the pantry and apply to every future list. */
    public function setPantry(int $userId, object $item, ?bool $staple, ?float $haveQty, bool $haveQtyGiven): void
    {
        $p = DB::table('pantry_items')->where('user_id', $userId)->where('ingredient_key', $item->ingredient_key)->first();
        $newStaple = $staple ?? (bool) ($p->is_staple ?? false);
        $qty = $haveQtyGiven ? $haveQty : ($p->qty ?? null);
        if (! $newStaple && $qty === null) {
            DB::table('pantry_items')->where('user_id', $userId)->where('ingredient_key', $item->ingredient_key)->delete();
        } else {
            DB::table('pantry_items')->updateOrInsert(['user_id' => $userId, 'ingredient_key' => $item->ingredient_key],
                ['label' => $item->label, 'qty' => $qty, 'unit' => $qty !== null ? $item->unit : null, 'is_staple' => $newStaple, 'updated_at' => now(), 'created_at' => $p->created_at ?? now()]);
        }
        // Reflect it on THIS list now, without waiting for a rebuild.
        $have = $qty !== null && $item->qty !== null ? round(min((float) $qty, (float) $item->qty), 2) : null;
        $upd = ['have_qty' => $have, 'updated_at' => now()];
        if ($item->state === 'need' && ($newStaple || ($have !== null && $item->qty !== null && $have >= (float) $item->qty))) {
            $upd['state'] = 'have';
        }
        DB::table('shopping_list_items')->where('id', $item->id)->update($upd);
    }

    /** @return array<string,mixed> */
    public function payload(int $userId, int $listId): array
    {
        $l = $this->own($userId, $listId);
        $staples = DB::table('pantry_items')->where('user_id', $userId)->where('is_staple', true)->pluck('ingredient_key')->all();

        $recipes = DB::table('shopping_list_recipes as r')->leftJoin('meals as m', 'm.id', '=', 'r.meal_id')->where('r.list_id', $listId)
            ->get(['r.meal_id', 'r.meal_name', 'r.servings', 'r.yield_portions', 'r.meal_stamp', 'm.updated_at as meal_updated', 'm.name as live_name'])
            ->map(fn ($r) => ['meal_id' => $r->meal_id ? (int) $r->meal_id : null, 'name' => $r->live_name ?? $r->meal_name, 'servings' => (float) $r->servings,
                'yield' => (float) $r->yield_portions, 'missing' => $r->meal_id === null,
                'changed' => $r->meal_id === null || ($r->meal_updated !== null && $r->meal_stamp !== null && strtotime($r->meal_updated) > strtotime($r->meal_stamp))])->all();

        $order = array_flip($this->aisles()['order']);
        $items = DB::table('shopping_list_items')->where('list_id', $listId)->get()
            ->sortBy([fn ($a, $b) => ($order[$a->aisle] ?? 99) <=> ($order[$b->aisle] ?? 99), fn ($a, $b) => strcasecmp($a->label, $b->label)])->values()
            ->map(fn ($i) => [
                'id' => (int) $i->id, 'key' => $i->ingredient_key, 'label' => $i->label, 'qty' => $i->qty !== null ? (float) $i->qty : null, 'unit' => $i->unit,
                'have_qty' => $i->have_qty !== null ? (float) $i->have_qty : null,
                'need_qty' => $i->qty !== null ? max(0.0, round((float) $i->qty - (float) ($i->have_qty ?? 0), 2)) : null,
                'pack_size' => $i->pack_size !== null ? (float) $i->pack_size : null, 'aisle' => $i->aisle, 'state' => $i->state,
                'recipes' => json_decode($i->source_meals, true) ?: [], 'is_manual' => (bool) $i->is_manual, 'is_staple' => in_array($i->ingredient_key, $staples, true),
            ])->all();

        return ['id' => (int) $l->id, 'name' => $l->name, 'status' => $l->status, 'recipes' => $recipes, 'items' => $items,
            'stale' => (bool) array_filter($recipes, fn ($r) => $r['changed']), 'aisle_order' => $this->aisles()['order']];
    }
}
