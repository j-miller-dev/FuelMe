<?php

use App\Models\FoodLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->user = User::factory()->create());

function foodInput(array $o = []): array
{
    return $o + ['name' => 'Aldi Protein Bar', 'brand' => 'Aldi', 'store' => 'Aldi', 'basis' => 'per100', 'protein' => 30, 'carbs' => 35, 'fat' => 12, 'kcal' => 368];
}

it('requires login', function () {
    $this->postJson('/foods', foodInput())->assertUnauthorized();
    $this->get('/foods')->assertRedirect('/login');
});

it('creates a food from per-100 values and it is searchable and loggable straight away', function () {
    $r = $this->actingAs($this->user)->postJson('/foods', foodInput(['barcode' => '9300650018860', 'serving_label' => '1 bar (45 g)', 'serving_grams' => 45, 'fibre' => 4, 'sodium_mg' => 250]))->assertCreated();

    expect($r->json('food'))->toMatchArray(['name' => 'Aldi Protein Bar', 'brand' => 'Aldi', 'source' => 'user'])
        ->and($r->json('food.per100'))->toMatchArray(['kcal' => 368, 'protein' => 30, 'carbs' => 35, 'fat' => 12, 'fibre' => 4, 'sodium_mg' => 250])
        ->and($r->json('food.servings.0'))->toBe(['label' => '1 bar (45 g)', 'grams' => 45]);
    $row = DB::table('foods')->find($r->json('food.id'));
    expect($row->user_id)->toBe($this->user->id)->and($row->barcode)->toBe('9300650018860')->and((int) $row->quality)->toBe(100)->and(json_decode($row->stores))->toBe(['Aldi']);

    // it ranks first in search and logs like any food
    $id = $r->json('food.id');
    if (DB::getDriverName() === 'pgsql') {   // search is Postgres-only
        $this->actingAs($this->user)->getJson('/foods/search?q=protein+bar')->assertJsonPath('results.0.id', $id);
    }
    $this->actingAs($this->user)->postJson('/logs', ['items' => [['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'snack', 'source' => 'search', 'food_id' => $id, 'grams' => 45]]])
        ->assertCreated()->assertJsonPath('logs.0.kcal', 165.6);
});

it('converts per-serve values and gives the serving a default label', function () {
    $r = $this->actingAs($this->user)->postJson('/foods', ['name' => 'Muesli bar', 'basis' => 'serving', 'serving_grams' => 40, 'protein' => 4, 'carbs' => 20, 'fat' => 2, 'kcal' => 125])->assertCreated();
    expect($r->json('food.per100'))->toMatchArray(['kcal' => 312.5, 'protein' => 10, 'carbs' => 50, 'fat' => 5])
        ->and($r->json('food.servings.0.label'))->toBe('1 serving (40 g)');
});

it('derives calories from macros when blank and warns when they disagree', function () {
    $r = $this->actingAs($this->user)->postJson('/foods', foodInput(['kcal' => null, 'name' => 'Derived']))->assertCreated();
    expect($r->json('food.per100.kcal'))->toBe(368)->and($r->json('warnings'))->toBe([]);   // 4*30 + 4*35 + 9*12
});

it('flags a label whose calories do not match its macros', function () {
    $r = $this->actingAs($this->user)->postJson('/foods', foodInput(['kcal' => 600]))->assertCreated();
    expect($r->json('warnings'))->toHaveCount(1)->and($r->json('food.needs_check'))->toBeTrue();
});

it('rejects bad input with a readable message', function (array $over, string $field) {
    $this->actingAs($this->user)->postJson('/foods', foodInput($over))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'macros > 100 g' => [['protein' => 70, 'carbs' => 70], 'nutrition'],
    'per-serve with no size' => [['basis' => 'serving'], 'nutrition'],
    'bad check digit' => [['barcode' => '9300650018861'], 'barcode'],
    'bad basis' => [['basis' => 'per-cup'], 'basis'],
]);

it('refuses a barcode that is already in the database and says which food has it', function () {
    $id = DB::table('foods')->insertGetId(['source' => 'off', 'source_id' => '9300650018860', 'barcode' => '9300650018860', 'name' => 'Vegemite', 'quality' => 60,
        'per100_kcal' => 205, 'per100_protein' => 25, 'per100_carbs' => 19, 'per100_fat' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $r = $this->actingAs($this->user)->postJson('/foods', foodInput(['barcode' => '9300650018860']))->assertStatus(422);
    expect($r->json('existing_food_id'))->toBe($id);
    // a UPC-A spelling of an EAN-13 already stored counts as the same barcode
    DB::table('foods')->where('id', $id)->update(['barcode' => '0036000291452', 'source_id' => '0036000291452']);
    $this->actingAs($this->user)->postJson('/foods', foodInput(['barcode' => '036000291452']))->assertStatus(422)->assertJsonPath('existing_food_id', $id);
});

it('edits your own food, replaces its serving, and leaves old logs untouched', function () {
    $id = $this->actingAs($this->user)->postJson('/foods', foodInput(['serving_grams' => 45]))->json('food.id');
    $this->actingAs($this->user)->postJson('/logs', ['items' => [['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'snack', 'source' => 'search', 'food_id' => $id, 'grams' => 100]]]);

    $r = $this->actingAs($this->user)->putJson("/foods/$id", foodInput(['name' => 'Aldi Protein Bar v2', 'protein' => 20, 'carbs' => 20, 'fat' => 10, 'kcal' => 250, 'serving_grams' => 60, 'serving_label' => '1 big bar']))->assertOk();
    expect($r->json('food'))->toMatchArray(['name' => 'Aldi Protein Bar v2'])->and($r->json('food.servings'))->toBe([['label' => '1 big bar', 'grams' => 60]]);
    expect(FoodLog::first()->name)->toBe('Aldi Protein Bar')->and(FoodLog::first()->kcal)->toBe(368.0);   // history is a snapshot
});

it('lets you keep your own barcode when editing, but not take another food\'s', function () {
    $a = $this->actingAs($this->user)->postJson('/foods', foodInput(['barcode' => '9300650018860']))->json('food.id');
    $this->actingAs($this->user)->putJson("/foods/$a", foodInput(['barcode' => '9300650018860', 'name' => 'Renamed']))->assertOk();
    $b = $this->actingAs($this->user)->postJson('/foods', foodInput(['name' => 'Other']))->json('food.id');
    $this->actingAs($this->user)->putJson("/foods/$b", foodInput(['barcode' => '9300650018860']))->assertStatus(422);
});

it('deletes your food but keeps the log history (name and macros)', function () {
    $id = $this->actingAs($this->user)->postJson('/foods', foodInput())->json('food.id');
    $this->actingAs($this->user)->postJson('/logs', ['items' => [['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'snack', 'source' => 'search', 'food_id' => $id, 'grams' => 50]]]);

    $this->actingAs($this->user)->deleteJson("/foods/$id")->assertOk();
    $log = FoodLog::first();
    expect(DB::table('foods')->find($id))->toBeNull()->and($log->food_id)->toBeNull()->and($log->kcal)->toBe(184.0)->and($log->name)->toContain('Protein Bar');
});

it('will not let you edit or delete a shared food or someone else\'s', function () {
    $shared = DB::table('foods')->insertGetId(['source' => 'afcd', 'source_id' => 'F1', 'name' => 'Egg', 'quality' => 90, 'per100_kcal' => 143, 'per100_protein' => 12, 'per100_carbs' => 1, 'per100_fat' => 9, 'created_at' => now(), 'updated_at' => now()]);
    $mine = $this->actingAs($this->user)->postJson('/foods', foodInput())->json('food.id');
    $other = User::factory()->create();

    $this->actingAs($this->user)->putJson("/foods/$shared", foodInput())->assertNotFound();
    $this->actingAs($this->user)->deleteJson("/foods/$shared")->assertNotFound();
    $this->actingAs($other)->putJson("/foods/$mine", foodInput())->assertNotFound();
    $this->actingAs($other)->deleteJson("/foods/$mine")->assertNotFound();
    expect(DB::table('foods')->whereIn('id', [$shared, $mine])->count())->toBe(2);
});

it('lists only your own foods on the Foods page', function () {
    $this->actingAs($this->user)->postJson('/foods', foodInput(['name' => 'Zeta']));
    $this->actingAs($this->user)->postJson('/foods', foodInput(['name' => 'Alpha']));
    $other = User::factory()->create();
    $this->actingAs($other)->postJson('/foods', foodInput(['name' => 'Theirs']));
    DB::table('foods')->insert(['source' => 'afcd', 'source_id' => 'F1', 'name' => 'Egg', 'quality' => 90, 'per100_kcal' => 143, 'per100_protein' => 12, 'per100_carbs' => 1, 'per100_fat' => 9, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->user)->get('/foods')->assertInertia(fn (Assert $p) => $p->component('Foods')->has('foods', 2)->where('foods.0.name', 'Alpha')->where('foods.1.name', 'Zeta'));
});
