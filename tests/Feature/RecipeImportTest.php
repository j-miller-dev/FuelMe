<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Matching ingredients needs the Postgres food search.
beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Recipe matching needs PostgreSQL.');
    }
    config(['fuel.capture.api_key' => null]);
});

function rfood(string $name, array $o = []): int
{
    return DB::table('foods')->insertGetId($o + ['source' => 'afcd', 'source_id' => 'R' . Str::random(8), 'name' => $name, 'quality' => 90,
        'per100_kcal' => 150, 'per100_protein' => 20, 'per100_carbs' => 1, 'per100_fat' => 7, 'created_at' => now(), 'updated_at' => now()]);
}

function note(string $name, string $md): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $md);
}

const BOWL = "---\nservings: 4\ntags: [dinner, meal-prep]\n---\n# Chicken rice bowl\n\n## Ingredients\n- 600 g chicken breast\n- 2 eggs\n- 3 cloves garlic, crushed\n- 1 tbsp zzqxv blorp\n\n## Method\n1. Cook.\n";

it('reads a note, matches each ingredient to a food, and keeps prep notes apart', function () {
    $u = loggedInUser();
    $chicken = rfood('Chicken, breast, lean flesh, raw');
    $egg = rfood('Egg, chicken, whole, raw', ['per100_kcal' => 143]);
    DB::table('food_servings')->insert(['food_id' => $egg, 'label' => '1 egg (50 g)', 'grams' => 50]);

    $r = $this->actingAs($u)->post('/meals/import/preview', ['files' => [note('chicken-rice-bowl.md', BOWL)]], ['Accept' => 'application/json'])->assertOk();

    $rec = $r->json('recipes.0');
    expect($rec['title'])->toBe('Chicken rice bowl')->and($rec['servings'])->toEqual(4)->and($rec['tags'])->toBe(['prep']);
    $rows = collect($rec['ingredients']);
    expect($rows)->toHaveCount(4)
        ->and($rows[0]['match']['chosen']['food_id'])->toBe($chicken)->and($rows[0]['match']['chosen']['grams'])->toEqual(600)
        ->and($rows[1]['match']['chosen']['food_id'])->toBe($egg)->and($rows[1]['match']['chosen']['grams'])->toEqual(100)   // 2 x your stored 50 g
        ->and($rows[2]['note'])->toBe('crushed')
        ->and($rows[3]['match']['confidence'])->toBe('red')->and($rows[3]['match']['chosen'])->toBeNull();
    expect(DB::table('meals')->count())->toBe(0);   // preview never saves
});

it('lets a store named in brackets pick that store\'s product', function () {
    $u = loggedInUser();
    rfood('Chicken, breast, lean flesh, raw');
    $woolies = rfood('Chicken Breast Fillets', ['source' => 'off', 'brand' => 'Woolworths', 'stores' => json_encode(['Woolworths']), 'quality' => 60]);
    rfood('Chicken Breast Fillets', ['source' => 'off', 'brand' => 'Coles', 'stores' => json_encode(['Coles']), 'quality' => 60]);

    $r = $this->actingAs($u)->post('/meals/import/preview', ['files' => [note('Stir fry.md', "## Ingredients\n- 500 g chicken breast (Woolworths)\n")]], ['Accept' => 'application/json'])->assertOk();

    $m = $r->json('recipes.0.ingredients.0.match');
    expect($m['parsed']['store'])->toBe('woolworths')->and($m['chosen']['food_id'])->toBe($woolies);
    expect(collect($m['candidates'])->pluck('id'))->toContain($woolies)->and($r->json('recipes.0.title'))->toBe('Stir fry');
});

it('rejects other file types and too many files', function () {
    $u = loggedInUser();
    $this->actingAs($u)->post('/meals/import/preview', ['files' => [UploadedFile::fake()->create('x.pdf', 5)]], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->actingAs($u)->post('/meals/import/preview', ['files' => array_map(fn ($i) => note("n$i.md", '- 1 egg'), range(1, 41))], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->actingAs($u)->post('/meals/import/preview', [], ['Accept' => 'application/json'])->assertUnprocessable();
});

it('says so when a note has no ingredients and still returns the others', function () {
    $u = loggedInUser();
    rfood('Egg, chicken, whole, raw');
    $r = $this->actingAs($u)->post('/meals/import/preview', ['files' => [note('Ideas.md', "# Ideas\n\nlearn to bake\n"), note('Eggs.md', "## Ingredients\n- 100 g egg\n")]], ['Accept' => 'application/json'])->assertOk();
    expect($r->json('recipes.0.warnings'))->toContain('No ingredients found in this note.')->and($r->json('recipes.1.ingredients'))->toHaveCount(1);
});

it('requires you to be signed in', function () {
    $this->post('/meals/import/preview', [], ['Accept' => 'application/json'])->assertUnauthorized();
});
