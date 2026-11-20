<?php

use App\Domain\Shopping\AisleMapper;
use App\Domain\Shopping\ShoppingListBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @param array<string,float> $lines food name => grams */
function recipe($test, $user, string $name, float $yield, array $lines): array
{
    $items = [];
    foreach ($lines as $food => $grams) {
        $id = DB::table('foods')->where('name', $food)->value('id') ?? ingredient($food, [100, 5, 10, 2]);
        $items[] = ['food_id' => $id, 'grams' => $grams];
    }

    return $test->actingAs($user)->postJson('/meals', ['name' => $name, 'yield_portions' => $yield, 'cook_loss' => 0, 'tags' => [], 'items' => $items])->assertCreated()->json('meal');
}

function makeList($test, $user, array $picks): array
{
    $id = $test->actingAs($user)->postJson('/shopping', ['recipes' => $picks])->assertCreated()->json('id');

    return $test->actingAs($user)->postJson("/shopping/{$id}/refresh")->json('list');
}

function line(array $list, string $label): ?array
{
    return collect($list['items'])->firstWhere('label', $label);
}

it('merges ingredients shared by several recipes into one line each (acceptance 1)', function () {
    $u = loggedInUser();
    $a = recipe($this, $u, 'Bolognese', 4, ['Onion, brown, raw' => 200, 'Garlic, raw' => 10, 'Olive oil' => 30]);
    $b = recipe($this, $u, 'Curry', 4, ['Onion, brown, raw' => 300, 'Garlic, raw' => 20, 'Olive oil' => 15]);
    $c = recipe($this, $u, 'Stir fry', 2, ['Onion, brown, raw' => 100, 'Garlic, raw' => 5, 'Olive oil' => 10]);

    $list = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 4], ['meal_id' => $b['id'], 'servings' => 4], ['meal_id' => $c['id'], 'servings' => 2]]);

    expect($list['items'])->toHaveCount(3)
        ->and(line($list, 'Onion, brown, raw'))->toMatchArray(['qty' => 600, 'unit' => 'g', 'aisle' => 'Produce'])
        ->and(line($list, 'Onion, brown, raw')['recipes'])->toEqualCanonicalizing(['Bolognese', 'Curry', 'Stir fry'])
        ->and(line($list, 'Garlic, raw')['qty'])->toEqual(35)
        ->and(line($list, 'Olive oil')['qty'])->toEqual(55);
});

it('scales servings exactly and keeps tick states when servings change (acceptance 2 and 4)', function () {
    $u = loggedInUser();
    $a = recipe($this, $u, 'Bolognese', 4, ['Minced beef' => 500, 'Onion, brown, raw' => 200]);
    $list = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 4]]);
    $beef = line($list, 'Minced beef');
    $this->actingAs($u)->patchJson("/shopping/items/{$beef['id']}", ['state' => 'bought'])->assertOk();

    $r = $this->actingAs($u)->putJson("/shopping/{$list['id']}/recipes", ['recipes' => [['meal_id' => $a['id'], 'servings' => 6]]])->assertOk()->json('list');

    expect(line($r, 'Minced beef'))->toMatchArray(['qty' => 750, 'state' => 'bought'])   // 500 x 6/4
        ->and(line($r, 'Onion, brown, raw')['qty'])->toEqual(300)
        ->and(line($r, 'Onion, brown, raw')['state'])->toBe('need');
});

it('subtracts pantry stock without going below zero, and moves staples to Have (acceptance 3)', function () {
    $u = loggedInUser();
    $a = recipe($this, $u, 'Rice bowl', 2, ['Rice, white, raw' => 400, 'Salt' => 5]);
    $list = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 2]]);

    $r = $this->actingAs($u)->patchJson('/shopping/items/' . line($list, 'Rice, white, raw')['id'], ['have_qty' => 150])->json('list');
    expect(line($r, 'Rice, white, raw'))->toMatchArray(['qty' => 400, 'have_qty' => 150, 'need_qty' => 250, 'state' => 'need']);

    $r = $this->actingAs($u)->patchJson('/shopping/items/' . line($list, 'Rice, white, raw')['id'], ['have_qty' => 900])->json('list');
    expect(line($r, 'Rice, white, raw'))->toMatchArray(['have_qty' => 400, 'need_qty' => 0, 'state' => 'have']);   // capped, never negative

    $this->actingAs($u)->patchJson('/shopping/items/' . line($list, 'Salt')['id'], ['is_staple' => true])->assertOk();
    $next = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 4]]);   // a NEW list
    expect(line($next, 'Salt'))->toMatchArray(['state' => 'have', 'is_staple' => true])
        ->and(line($next, 'Rice, white, raw'))->toMatchArray(['qty' => 800, 'have_qty' => 800, 'need_qty' => 0]);
});

it('replays offline actions without duplicating or undoing anything (acceptance 6)', function () {
    $u = loggedInUser();
    $a = recipe($this, $u, 'Eggs on toast', 1, ['Egg, whole' => 120, 'Bread, white' => 80]);
    $list = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 1]]);
    $egg = line($list, 'Egg, whole');
    $uuid = (string) Str::uuid();
    $ops = [['op' => 'state', 'item_id' => $egg['id'], 'state' => 'bought'], ['op' => 'add', 'uuid' => $uuid, 'label' => 'Toilet paper'], ['op' => 'state', 'item_id' => $egg['id'], 'state' => 'need'], ['op' => 'state', 'item_id' => $egg['id'], 'state' => 'bought']];

    $first = $this->actingAs($u)->postJson("/shopping/{$list['id']}/sync", ['ops' => $ops])->assertOk()->json('list');
    $again = $this->actingAs($u)->postJson("/shopping/{$list['id']}/sync", ['ops' => $ops])->assertOk()->json('list');   // the connection dropped: it is sent twice

    expect($again['items'])->toHaveCount(3)->and($again)->toEqual($first)
        ->and(line($again, 'Egg, whole')['state'])->toBe('bought')
        ->and(line($again, 'Toilet paper'))->toMatchArray(['is_manual' => true, 'aisle' => 'Other']);
});

it('flags a recipe edited after the list was made and does not change the list until confirmed (acceptance 8)', function () {
    $u = loggedInUser();
    $a = recipe($this, $u, 'Curry', 2, ['Chicken breast, raw' => 400]);
    $list = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 2]]);
    expect($list['stale'])->toBeFalse();

    $this->travel(5)->minutes();
    $this->actingAs($u)->putJson("/meals/{$a['id']}", ['name' => 'Curry', 'yield_portions' => 2, 'cook_loss' => 0, 'tags' => [],
        'items' => [['food_id' => $a['items'][0]['food_id'], 'grams' => 800]]])->assertOk();

    $view = $this->actingAs($u)->get("/shopping/{$list['id']}")->assertOk()->viewData('page')['props']['list'];
    expect($view['stale'])->toBeTrue()->and(line($view, 'Chicken breast, raw')['qty'])->toEqual(400);   // unchanged

    $r = $this->actingAs($u)->postJson("/shopping/{$list['id']}/refresh")->json('list');
    expect($r['stale'])->toBeFalse()->and(line($r, 'Chicken breast, raw')['qty'])->toEqual(800);
});

it('keeps manual items through a rebuild and refuses to delete a recipe ingredient directly', function () {
    $u = loggedInUser();
    $a = recipe($this, $u, 'Curry', 2, ['Chicken breast, raw' => 400]);
    $list = makeList($this, $u, [['meal_id' => $a['id'], 'servings' => 2]]);
    $r = $this->actingAs($u)->postJson("/shopping/{$list['id']}/items", ['label' => 'Paper towels', 'uuid' => (string) Str::uuid()])->assertCreated()->json('list');

    $this->actingAs($u)->deleteJson('/shopping/items/' . line($r, 'Chicken breast, raw')['id'])->assertStatus(422);
    $rebuilt = $this->actingAs($u)->postJson("/shopping/{$list['id']}/refresh")->json('list');
    expect(line($rebuilt, 'Paper towels'))->not->toBeNull();

    $empty = $this->actingAs($u)->putJson("/shopping/{$list['id']}/recipes", ['recipes' => []])->assertOk()->json('list');
    expect($empty['items'])->toHaveCount(1)->and($empty['items'][0]['label'])->toBe('Paper towels');
});

it('keeps lists private to their owner', function () {
    $a = loggedInUser();
    $m = recipe($this, $a, 'Curry', 2, ['Chicken breast, raw' => 400]);
    $list = makeList($this, $a, [['meal_id' => $m['id'], 'servings' => 2]]);
    $b = loggedInUser();

    $this->actingAs($b)->get("/shopping/{$list['id']}")->assertNotFound();
    $this->actingAs($b)->postJson("/shopping/{$list['id']}/refresh")->assertNotFound();
    $this->actingAs($b)->patchJson('/shopping/items/' . $list['items'][0]['id'], ['state' => 'bought'])->assertNotFound();
    $this->actingAs($b)->postJson('/shopping', ['recipes' => [['meal_id' => $m['id'], 'servings' => 2]]])->assertNotFound();
});

// ---- pure: the aggregation rules that need no database ---------------------------------------------------------------------

it('merges "1 onion" with "200 g onion" through the average unit weight (acceptance 5)', function () {
    $b = new ShoppingListBuilder(['vegetable' => 'Produce'], ['Produce', 'Other']);
    $r1 = ['id' => 'a', 'name' => 'A', 'yield' => 1, 'ingredients' => [['key' => 'food:1', 'name' => 'Onion', 'qty' => 1, 'unit' => 'count', 'category' => 'vegetable', 'unit_g' => 150]]];
    $r2 = ['id' => 'b', 'name' => 'B', 'yield' => 1, 'ingredients' => [['key' => 'food:1', 'name' => 'Onion', 'qty' => 200, 'unit' => 'g', 'category' => 'vegetable', 'unit_g' => 150]]];

    $out = $b->build([['recipe' => $r1, 'servings' => 1], ['recipe' => $r2, 'servings' => 1]]);

    expect($out['items'])->toHaveCount(1)->and($out['items'][0])->toMatchArray(['qty' => 350.0, 'unit' => 'g']);
});

it('rounds up to whole packs (acceptance 7)', function () {
    expect(ShoppingListBuilder::roundToPacks(640, 500))->toBe([2, 1000.0])->and(ShoppingListBuilder::roundToPacks(500, 500))->toBe([1, 500.0]);
});

it('maps foods to aisles by category, then name, else Other', function () {
    $m = AisleMapper::fromFile(base_path('data/aisle_keywords.json'));

    expect($m->aisle('Chicken breast, raw'))->toBe('Meat & seafood')->and($m->aisle('Onion, brown, raw'))->toBe('Produce')->and($m->aisle('Eggplant, raw'))->toBe('Produce')
        ->and($m->aisle('Milk, cow, full fat'))->toBe('Dairy & eggs')->and($m->aisle('Peanut butter'))->toBe('Pantry')->and($m->aisle('Rice, white, raw'))->toBe('Pantry')
        ->and($m->aisle('Anything', 'en:frozen-foods'))->toBe('Frozen')->and($m->aisle('Zzz thing'))->toBe('Other');
});
