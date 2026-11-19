<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();
});

function scan(string $code)
{
    return test()->actingAs(test()->user)->getJson('/foods/barcode?code=' . urlencode($code));
}

function offProduct(array $over = [], array $nutriments = []): array
{
    return ['status' => 1, 'code' => '9300650018860', 'product' => $over + [
        'code' => '9300650018860', 'product_name' => 'Vegemite', 'brands' => 'Mondelez Australia Foods', 'stores' => 'Coles, IGA',
        'categories' => 'Yeast extract spreads, Spreads', 'quantity' => '560g', 'serving_size' => '5g', 'serving_quantity' => 5,
        'nutriments' => $nutriments + ['energy-kcal_100g' => 205.8, 'proteins_100g' => 25.4, 'carbohydrates_100g' => 19.9, 'fat_100g' => 1, 'fiber_100g' => 7.8, 'sugars_100g' => 2.2, 'saturated-fat_100g' => 1, 'sodium_100g' => 3.5],
    ]];
}

it('requires login and a code', function () {
    $this->getJson('/foods/barcode?code=9300650018860')->assertUnauthorized();
    $this->actingAs($this->user)->getJson('/foods/barcode')->assertStatus(422);
});

it('finds a product already in the local database without calling OFF', function () {
    Http::fake();
    $id = DB::table('foods')->insertGetId(['source' => 'off', 'source_id' => '9300650018860', 'barcode' => '9300650018860', 'name' => 'Vegemite', 'brand' => 'Mondelez',
        'quality' => 70, 'per100_kcal' => 205.8, 'per100_protein' => 25.4, 'per100_carbs' => 19.9, 'per100_fat' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $r = scan('9300650018860')->assertOk();
    expect($r->json('status'))->toBe('found')->and($r->json('food.id'))->toBe($id)->and($r->json('food.name'))->toBe('Vegemite');
    Http::assertNothingSent();
});

it('matches a UPC-A scan to the same product stored as EAN-13', function () {
    Http::fake();
    $id = DB::table('foods')->insertGetId(['source' => 'off', 'source_id' => '0036000291452', 'barcode' => '0036000291452', 'name' => 'Kleenex-ish', 'quality' => 60,
        'per100_kcal' => 100, 'per100_protein' => 1, 'per100_carbs' => 20, 'per100_fat' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(scan('036000291452')->json('food.id'))->toBe($id);
    Http::assertNothingSent();
});

it('looks an unknown product up live, caches it forever, and never asks twice', function () {
    Http::fake(['*/api/v2/product/9300650018860*' => Http::response(offProduct())]);

    $first = scan('9300650018860')->assertOk();
    expect($first->json('status'))->toBe('found')->and($first->json('food.name'))->toBe('Vegemite')->and($first->json('food.brand'))->toBe('Mondelez Australia Foods')
        ->and($first->json('food.per100.kcal'))->toBe(205.8)->and($first->json('food.per100.sodium_mg'))->toBe(3500)
        ->and($first->json('food.servings.0.label'))->toBe('5g')->and($first->json('food.servings.0.grams'))->toBe(5);
    expect(DB::table('foods')->where('barcode', '9300650018860')->value('source'))->toBe('off');

    $second = scan('9300650018860');
    expect($second->json('status'))->toBe('found')->and($second->json('food.id'))->toBe($first->json('food.id'));
    Http::assertSentCount(1);
});

it('sends our own User-Agent and asks only for the fields it needs', function () {
    Http::fake(['*' => Http::response(offProduct())]);
    scan('9300650018860');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/9300650018860') && str_contains($req->url(), 'fields=')
        && str_starts_with($req->header('User-Agent')[0], 'FUEL/'));
});

it('remembers a miss so a rescan does not hit OFF again, then reports not found', function () {
    Http::fake(['*' => Http::response(['status' => 0, 'status_verbose' => 'product not found'], 404)]);
    expect(scan('9300650018860')->json('status'))->toBe('not_found');
    expect(scan('9300650018860')->json('status'))->toBe('not_found');
    Http::assertSentCount(1);
});

it('returns the known details to pre-fill the create form when OFF has no usable nutrition', function () {
    Http::fake(['*' => Http::response(offProduct([], ['energy-kcal_100g' => null, 'proteins_100g' => null]))]);
    $r = scan('9300650018860')->assertOk();
    expect($r->json('status'))->toBe('incomplete')
        ->and($r->json('prefill'))->toMatchArray(['barcode' => '9300650018860', 'name' => 'Vegemite', 'brand' => 'Mondelez Australia Foods', 'serving_label' => '5g', 'serving_grams' => 5]);
    expect(DB::table('foods')->count())->toBe(0);   // nothing half-baked is stored
});

it('says offline (and does not cache a miss) when OFF is unreachable or rate limiting', function () {
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->pushStatus(429)->push(offProduct())]);
    expect(scan('9300650018860')->json('status'))->toBe('offline');      // timeout
    expect(scan('9300650018860')->json('status'))->toBe('offline');      // rate limited
    expect(scan('9300650018860')->json('status'))->toBe('found');        // recovered: nothing was cached as a miss
});

it('rejects a misread barcode without calling OFF', function () {
    Http::fake();
    expect(scan('9300650018861')->json('status'))->toBe('invalid');      // bad check digit
    expect(scan('12345')->json('status'))->toBe('invalid');
    Http::assertNothingSent();
});

it('does not let one user see another user\'s private product by barcode', function () {
    Http::fake(['*' => Http::response(['status' => 0], 404)]);
    $other = User::factory()->create();
    DB::table('foods')->insert(['source' => 'user', 'user_id' => $other->id, 'barcode' => '9300650018860', 'name' => 'Theirs', 'quality' => 100,
        'per100_kcal' => 100, 'per100_protein' => 1, 'per100_carbs' => 1, 'per100_fat' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(scan('9300650018860')->json('status'))->toBe('not_found');
});
