<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Search is Postgres-only (tsvector, pg_trgm, unaccent). CI runs the Feature suite on Postgres 18; the SQLite job skips these.
beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Food search needs PostgreSQL.');
    }
    $this->user = User::factory()->create();
});

function food(string $name, array $o = []): int
{
    return DB::table('foods')->insertGetId($o + [
        'source' => 'afcd', 'source_id' => 'T' . bin2hex(random_bytes(4)), 'name' => $name, 'quality' => 80,
        'per100_kcal' => 100, 'per100_protein' => 10, 'per100_carbs' => 10, 'per100_fat' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function names(array $json): array
{
    return array_column($json['results'], 'name');
}

function find(string $q)
{
    return test()->actingAs(test()->user)->getJson('/foods/search?q=' . urlencode($q));
}

it('requires login', function () {
    $this->getJson('/foods/search?q=egg')->assertUnauthorized();
});

it('finds AU synonyms: capsicum <-> bell pepper, mince <-> ground beef, rockmelon <-> cantaloupe', function () {
    food('Pepper, bell, red, raw');
    food('Beef, ground, lean, raw');
    food('Cantaloupe, raw');
    food('Pumpkin, raw');

    expect(names(find('capsicum')->json()))->toContain('Pepper, bell, red, raw')->not->toContain('Pumpkin, raw');
    expect(names(find('mince')->json()))->toContain('Beef, ground, lean, raw');
    expect(names(find('rockmelon')->json()))->toBe(['Cantaloupe, raw']);
    expect(names(find('yoghurt')->json()))->toBe([]);           // nothing seeded, and no crash on an empty result
});

it('weetbix 2 finds the Weet-Bix food, quantity 2, and uses its stored serving for grams', function () {
    $id = food('Breakfast cereal, whole wheat, biscuit (Sanitarium Weet-Bix)');
    DB::table('food_servings')->insert(['food_id' => $id, 'label' => '2 biscuits (30 g)', 'grams' => 30]);
    food('Biscuit, sweet, plain');

    $j = find('weetbix 2')->assertOk()->json();
    expect($j['query']['term'])->toBe('weetbix')->and($j['query']['qty'])->toBe(2)
        ->and($j['results'][0]['name'])->toContain('Weet-Bix')
        ->and($j['results'][0]['portion'])->toBe(['grams' => 30, 'label' => '2 × biscuit']);
});

it('2 eggs: plural matches, whole egg beats eggplant', function () {
    food('Eggplant, unpeeled, fresh, raw');
    food('Egg, chicken, whole, raw');
    $j = find('2 eggs')->json();
    expect($j['results'][0]['name'])->toBe('Egg, chicken, whole, raw')->and($j['query']['qty'])->toBe(2);
});

it('pre-fills grams from quantity: 150g, 300ml of a liquid, 1 cup with density', function () {
    food('Chicken, breast, lean flesh, raw');
    food('Milk, cow, fluid, regular fat', ['is_liquid' => true, 'density_g_ml' => 1.03]);

    expect(find('150g chicken breast')->json('results.0.portion.grams'))->toBe(150)
        ->and(find('300ml milk')->json('results.0.portion.grams'))->toBe(300);
    expect(find('1 cup milk')->json('results.0.portion.grams'))->toBe(250);
});

it('ignores accents and tolerates a typo', function () {
    food('Crème fraîche, regular');
    food('Zucchini, green skin, fresh, unpeeled, raw');
    expect(names(find('creme fraiche')->json()))->toContain('Crème fraîche, regular');
    expect(names(find('zuchini')->json()))->toContain('Zucchini, green skin, fresh, unpeeled, raw');
});

it('prefers names that start with the query and shorter names on ties', function () {
    food('Ice confection, stick, milk-based, various flavours');
    food('Milk, cow, fluid, skim');
    food('Milk, cow, fluid, skim, with added protein and calcium');
    expect(names(find('milk')->json()))->toBe([
        'Milk, cow, fluid, skim', 'Milk, cow, fluid, skim, with added protein and calcium', 'Ice confection, stick, milk-based, various flavours',
    ]);
});

it('ranks by personal use and recency', function () {
    $a = food('Bread, white, commercial');
    $b = food('Bread, wholemeal, commercial', ['use_count' => 40, 'last_used_at' => now()->subDay()]);
    expect(find('bread')->json('results.0.id'))->toBe($b);
    $c = food('Bread, rye, commercial', ['use_count' => 40, 'last_used_at' => now()->subYear()]);
    expect(array_column(find('bread')->json('results'), 'id'))->toEqualCanonicalizing([$a, $b, $c])->and(find('bread')->json('results.0.id'))->toBe($b);
});

it('puts a personal alias first and keeps user foods private', function () {
    $mine = food('Chobani Fit Vanilla', ['source' => 'user', 'source_id' => null, 'user_id' => $this->user->id]);
    $theirs = food('Chobani Secret', ['source' => 'user', 'source_id' => null, 'user_id' => User::factory()->create()->id]);
    food('Yoghurt, vanilla flavoured (~2%)');
    DB::table('food_aliases')->insert(['user_id' => $this->user->id, 'phrase' => 'yoghurt', 'food_id' => $mine, 'created_at' => now(), 'updated_at' => now()]);

    $ids = array_column(find('yoghurt')->json('results'), 'id');
    expect($ids[0])->toBe($mine)->and($ids)->not->toContain($theirs);
    expect(array_column(find('chobani')->json('results'), 'id'))->not->toContain($theirs);
});

it('handles hostile and empty input', function () {
    food('Egg, chicken, whole, raw');
    find("'; DROP TABLE foods; --")->assertOk();
    find('!!!')->assertOk()->assertJsonPath('results', []);
    $this->actingAs($this->user)->getJson('/foods/search?q=' . str_repeat('a', 130))->assertStatus(422);
    expect(DB::table('foods')->count())->toBe(1);
});

it('returns nothing for an empty query (recents live at /foods/quick)', function () {
    food('Recent thing', ['last_used_at' => now()]);
    expect(find('')->json('results'))->toBe([]);
});
