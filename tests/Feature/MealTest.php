<?php

use App\Models\FoodLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ingredient(string $name, array $per100): int
{
    return DB::table('foods')->insertGetId(['source' => 'afcd', 'source_id' => 'M' . Str::random(6), 'name' => $name, 'quality' => 90,
        'per100_kcal' => $per100[0], 'per100_protein' => $per100[1], 'per100_carbs' => $per100[2], 'per100_fat' => $per100[3], 'created_at' => now(), 'updated_at' => now()]);
}

/** Chicken and rice, 2 portions, 30% cooking loss: 635 kcal / 64.5 P / 80 C / 4.35 F and 245 g per portion (see MealMathTest). */
function makeMeal($test, $user, array $o = []): array
{
    $chicken = ingredient('Chicken breast, raw', [110, 23, 0, 1.5]);
    $rice = ingredient('Rice, white, raw', [360, 7, 80, 0.6]);

    return $test->actingAs($user)->postJson('/meals', $o + [
        'name' => 'Chicken and rice', 'yield_portions' => 2, 'cook_loss' => 0.3, 'tags' => ['prep', 'post'],
        'items' => [['food_id' => $chicken, 'grams' => 500], ['food_id' => $rice, 'grams' => 200]],
    ])->assertCreated()->json('meal');
}

function mealLog(int $mealId, array $o = []): array
{
    return $o + ['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'dinner', 'source' => 'meal', 'meal_id' => $mealId];
}

it('saves a meal and computes its macros per portion on the server', function () {
    $u = loggedInUser();
    $m = makeMeal($this, $u);

    expect($m['computed']['portion'])->toMatchArray(['grams' => 245, 'kcal' => 635, 'protein' => 64.5, 'carbs' => 80, 'fat' => 4.35])
        ->and($m['tags'])->toBe(['prep', 'post'])->and($m['items'])->toHaveCount(2);
});

it('logs one portion, a number of portions, or a weight, as a frozen snapshot', function () {
    $u = loggedInUser();
    $m = makeMeal($this, $u);

    $r = $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['portions' => 1.5, 'kcal' => 1])]])->assertCreated();
    expect($r->json('logs.0'))->toMatchArray(['name' => 'Chicken and rice', 'kcal' => 952.5, 'protein' => 96.75, 'grams' => 367.5, 'serving_label' => '1.5 portions', 'portions' => 1.5]);

    // 122.5 g of a 245 g portion is half a portion
    $w = $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['grams' => 122.5])]])->assertCreated();
    expect($w->json('logs.0'))->toMatchArray(['kcal' => 317.5, 'portions' => 0.5, 'serving_label' => '122.5 g']);
});

it('keeps old logs unchanged when the meal is edited or deleted', function () {
    $u = loggedInUser();
    $m = makeMeal($this, $u);
    $id = $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['portions' => 1])]])->json('logs.0.id');

    $this->actingAs($u)->putJson("/meals/{$m['id']}", ['name' => 'Chicken and rice', 'yield_portions' => 4, 'cook_loss' => 0.3, 'tags' => [],
        'items' => collect($m['items'])->map(fn ($i) => ['food_id' => $i['food_id'], 'grams' => $i['grams']])->all()])->assertOk();
    expect((float) FoodLog::find($id)->kcal)->toBe(635.0);

    $this->actingAs($u)->deleteJson("/meals/{$m['id']}")->assertOk();
    expect((float) FoodLog::find($id)->kcal)->toBe(635.0)->and(FoodLog::find($id)->meal_id)->toBeNull();
});

it('draws portions from a prep batch and never goes below zero', function () {
    $u = loggedInUser();
    $m = makeMeal($this, $u);
    $b = $this->actingAs($u)->postJson("/meals/{$m['id']}/batches", ['portions' => 3])->assertCreated()->json('meal.batches.0');
    expect($b['remaining'])->toEqual(3);

    $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['portions' => 2, 'batch_id' => $b['id']])]])->assertCreated();
    $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['portions' => 1.5, 'batch_id' => $b['id']])]])->assertStatus(422);
    $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['portions' => 1, 'batch_id' => $b['id']])]])->assertCreated();

    $left = $this->actingAs($u)->getJson('/meals/list')->json('meals.0.batches.0.remaining');
    expect($left)->toEqual(0);
});

it('keeps the batch count right when a logged portion is edited or removed', function () {
    $u = loggedInUser();
    $m = makeMeal($this, $u);
    $b = $this->actingAs($u)->postJson("/meals/{$m['id']}/batches", ['portions' => 4])->json('meal.batches.0');
    $log = $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'], ['portions' => 1, 'batch_id' => $b['id']])]])->json('logs.0');

    $this->actingAs($u)->patchJson("/logs/{$log['id']}", ['grams' => 490])->assertOk()->assertJsonPath('log.kcal', 1270)->assertJsonPath('log.portions', 2);
    expect($this->actingAs($u)->getJson('/meals/list')->json('meals.0.batches.0.remaining'))->toEqual(2);

    $this->actingAs($u)->deleteJson("/logs/{$log['id']}")->assertOk();
    expect($this->actingAs($u)->getJson('/meals/list')->json('meals.0.batches.0.remaining'))->toEqual(4);
});

it('will not log a meal whose ingredient has no energy data, and blocks deleting a food a meal uses', function () {
    $u = loggedInUser();
    $m = makeMeal($this, $u);
    $own = DB::table('foods')->insertGetId(['source' => 'user', 'user_id' => $u->id, 'name' => 'My oats', 'quality' => 100, 'per100_kcal' => 380, 'per100_protein' => 13, 'per100_carbs' => 60, 'per100_fat' => 7, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($u)->postJson('/meals', ['name' => 'Porridge', 'yield_portions' => 1, 'cook_loss' => 0, 'tags' => [], 'items' => [['food_id' => $own, 'grams' => 60]]])->assertCreated();

    $this->actingAs($u)->deleteJson("/foods/{$own}")->assertStatus(422);

    DB::table('foods')->where('id', $m['items'][0]['food_id'])->update(['per100_kcal' => null]);
    $this->actingAs($u)->postJson('/logs', ['items' => [mealLog($m['id'])]])->assertStatus(422);
});

it('keeps meals private to their owner', function () {
    $a = loggedInUser();
    $m = makeMeal($this, $a);
    $b = loggedInUser();

    $this->actingAs($b)->postJson('/logs', ['items' => [mealLog($m['id'])]])->assertNotFound();
    $this->actingAs($b)->putJson("/meals/{$m['id']}", ['name' => 'x', 'yield_portions' => 1, 'cook_loss' => 0, 'items' => [['food_id' => $m['items'][0]['food_id'], 'grams' => 1]]])->assertNotFound();
    $this->actingAs($b)->deleteJson("/meals/{$m['id']}")->assertNotFound();
    expect($this->actingAs($b)->getJson('/meals/list')->json('meals'))->toBe([]);
});

it('rejects an invalid recipe', function () {
    $u = loggedInUser();
    $f = ingredient('Thing', [100, 1, 1, 1]);
    $base = ['name' => 'X', 'yield_portions' => 1, 'cook_loss' => 0, 'tags' => [], 'items' => [['food_id' => $f, 'grams' => 100]]];

    $this->actingAs($u)->postJson('/meals', ['cook_loss' => 0.95] + $base)->assertStatus(422);
    $this->actingAs($u)->postJson('/meals', ['yield_portions' => 0] + $base)->assertStatus(422);
    $this->actingAs($u)->postJson('/meals', ['items' => []] + $base)->assertStatus(422);
    $this->actingAs($u)->postJson('/meals', ['tags' => ['nope']] + $base)->assertStatus(422);
});

it('survives a profile saved with a bad timezone', function () {
    $u = loggedInUser();
    DB::table('profiles')->where('user_id', $u->id)->update(['tz' => '0']);

    $this->actingAs($u)->get('/')->assertOk();
    $this->actingAs($u)->postJson('/logs', ['items' => [['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'snack', 'source' => 'quick', 'name' => 'x', 'kcal' => 100]]])->assertCreated();
});
