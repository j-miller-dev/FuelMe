<?php

use App\Models\FoodLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** A signed-in user who has finished onboarding (so a plan exists). */
function loggedInUser(): User
{
    $u = User::factory()->create();
    test()->actingAs($u)->post('/onboarding', [
        'sex' => 'male', 'dob' => now()->subYears(30)->subDays(10)->toDateString(), 'height_cm' => 180, 'weight_kg' => 74, 'body_fat_pct' => 14,
        'job' => 'on_feet', 'shift' => 'afternoon', 'training_age' => '1to3', 'lifting_style' => 'hit', 'energy_unit' => 'kcal',
        'phase' => 'bulk', 'rate_pct_per_week' => null, 'protein_g_per_kg' => 2.2, 'acknowledged' => true,
    ])->assertRedirect('/');

    return $u->fresh();
}

function egg(array $o = []): int
{
    return DB::table('foods')->insertGetId($o + ['source' => 'afcd', 'source_id' => 'E' . Str::random(6), 'name' => 'Egg, chicken, whole, raw', 'quality' => 90,
        'per100_kcal' => 143, 'per100_protein' => 12.6, 'per100_carbs' => 0.7, 'per100_fat' => 9.5, 'created_at' => now(), 'updated_at' => now()]);
}

function item(int $foodId, float $grams, array $o = []): array
{
    return $o + ['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'breakfast', 'source' => 'search', 'food_id' => $foodId, 'grams' => $grams];
}

it('logs a food with macros computed on the server, ignoring any client numbers', function () {
    $u = loggedInUser();
    $id = egg();

    $r = $this->actingAs($u)->postJson('/logs', ['items' => [item($id, 110, ['kcal' => 9999, 'protein' => 999])]])->assertCreated();

    expect($r->json('logs.0'))->toMatchArray(['name' => 'Egg, chicken, whole, raw', 'grams' => 110, 'kcal' => 157.3, 'protein' => 13.86, 'meal_slot' => 'breakfast']);
    expect(DB::table('foods')->where('id', $id)->value('use_count'))->toBe(1);
});

it('does not repeat the brand when the product name already contains it', function () {
    $u = loggedInUser();
    $a = egg(['name' => 'Weet-Bix Bites Honey Crunch', 'brand' => 'Weet-Bix']);
    $b = egg(['name' => 'Weet-Bix', 'brand' => 'Sanitarium']);
    $r = $this->actingAs($u)->postJson('/logs', ['items' => [item($a, 50), item($b, 30)]])->assertCreated();
    expect($r->json('logs.0.name'))->toBe('Weet-Bix Bites Honey Crunch')->and($r->json('logs.1.name'))->toBe('Sanitarium Weet-Bix');
});

it('is idempotent: a retried request does not log twice', function () {
    $u = loggedInUser();
    $payload = ['items' => [item(egg(), 50)]];

    $a = $this->actingAs($u)->postJson('/logs', $payload)->assertCreated()->json('logs.0.id');
    $b = $this->actingAs($u)->postJson('/logs', $payload)->assertCreated()->json('logs.0.id');

    expect($b)->toBe($a)->and(FoodLog::count())->toBe(1);
    expect(DB::table('foods')->value('use_count'))->toBe(1);   // and the retry did not inflate the ranking
});

it('quick-add takes raw macros and derives kcal when blank', function () {
    $u = loggedInUser();
    $r = $this->actingAs($u)->postJson('/logs', ['items' => [[
        'idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'snack', 'source' => 'quick', 'name' => 'Protein shake', 'protein' => 30, 'carbs' => 40, 'fat' => 10,
    ]]])->assertCreated();

    expect($r->json('logs.0'))->toMatchArray(['name' => 'Protein shake', 'kcal' => 370, 'food_id' => null, 'grams' => null]);
    $this->actingAs($u)->postJson('/logs', ['items' => [['idempotency_key' => (string) Str::uuid(), 'meal_slot' => 'snack', 'source' => 'quick']]])->assertStatus(422);
});

it('rejects bad input', function (array $over) {
    $u = loggedInUser();
    $this->actingAs($u)->postJson('/logs', ['items' => [item(egg(), 50, $over)]])->assertStatus(422);
})->with([
    'unknown meal' => [['meal_slot' => 'brunch']],
    'zero grams' => [['grams' => 0]],
    'huge grams' => [['grams' => 99999]],
    'bad key' => [['idempotency_key' => 'nope']],
    'missing food' => [['food_id' => 987654]],
    'bad source' => [['source' => 'magic']],
]);

it('refuses a food with no energy data, and another user\'s private food', function () {
    $u = loggedInUser();
    $blank = egg(['per100_kcal' => null]);
    $this->actingAs($u)->postJson('/logs', ['items' => [item($blank, 50)]])->assertStatus(422);

    $other = User::factory()->create();
    $theirs = egg(['source' => 'user', 'source_id' => null, 'user_id' => $other->id]);
    $this->actingAs($u)->postJson('/logs', ['items' => [item($theirs, 50)]])->assertNotFound();
});

it('puts late-night logs on the previous local day (03:00 rollover)', function () {
    $u = loggedInUser();
    // 02:00 Melbourne on 17 Nov 2026 (AEDT, UTC+11) = 15:00 UTC on 16 Nov
    $this->travelTo(new DateTimeImmutable('2026-11-16 15:00:00', new DateTimeZone('UTC')));
    $r = $this->actingAs($u)->postJson('/logs', ['items' => [item(egg(), 50)]])->assertCreated();
    expect($r->json('logs.0.local_date'))->toBe('2026-11-16');

    $this->travelTo(new DateTimeImmutable('2026-11-16 16:30:00', new DateTimeZone('UTC')));   // 03:30 local on the 17th
    $r = $this->actingAs($u)->postJson('/logs', ['items' => [item(egg(), 50)]])->assertCreated();
    expect($r->json('logs.0.local_date'))->toBe('2026-11-17');
});

it('editing the weight scales the stored snapshot, not the current food row', function () {
    $u = loggedInUser();
    $id = egg();
    $logId = $this->actingAs($u)->postJson('/logs', ['items' => [item($id, 100)]])->json('logs.0.id');

    DB::table('foods')->where('id', $id)->update(['per100_kcal' => 999, 'name' => 'Renamed food']);   // the food changes later
    $r = $this->actingAs($u)->patchJson("/logs/$logId", ['grams' => 200, 'meal_slot' => 'dinner'])->assertOk();

    expect($r->json('log'))->toMatchArray(['grams' => 200, 'kcal' => 286, 'protein' => 25.2, 'meal_slot' => 'dinner', 'name' => 'Egg, chicken, whole, raw']);
});

it('keeps history when a food is deleted', function () {
    $u = loggedInUser();
    $id = egg();
    $this->actingAs($u)->postJson('/logs', ['items' => [item($id, 100)]]);
    DB::table('foods')->where('id', $id)->delete();
    $log = FoodLog::first();
    expect($log->food_id)->toBeNull()->and($log->kcal)->toBe(143.0)->and($log->name)->toBe('Egg, chicken, whole, raw');
});

it('deletes a log and takes back the ranking boost', function () {
    $u = loggedInUser();
    $id = egg();
    $logId = $this->actingAs($u)->postJson('/logs', ['items' => [item($id, 100)]])->json('logs.0.id');
    $this->actingAs($u)->deleteJson("/logs/$logId")->assertOk();
    expect(FoodLog::count())->toBe(0)->and(DB::table('foods')->where('id', $id)->value('use_count'))->toBe(0);
});

it('does not let one user touch another user\'s logs', function () {
    $a = loggedInUser();
    $logId = $this->actingAs($a)->postJson('/logs', ['items' => [item(egg(), 100)]])->json('logs.0.id');
    $b = User::factory()->create();
    $this->actingAs($b)->patchJson("/logs/$logId", ['grams' => 5])->assertNotFound();
    $this->actingAs($b)->deleteJson("/logs/$logId")->assertNotFound();
    expect(FoodLog::count())->toBe(1);
});

it('copies yesterday onto today, one meal or the whole day, and a double tap is harmless', function () {
    $u = loggedInUser();
    $id = egg();
    $this->actingAs($u)->postJson('/logs', ['local_date' => '2026-11-15', 'items' => [
        item($id, 100, ['meal_slot' => 'breakfast']), item($id, 50, ['meal_slot' => 'dinner']),
    ]])->assertCreated();
    $this->travelTo(new DateTimeImmutable('2026-11-16 02:00:00', new DateTimeZone('UTC')));   // midday on the 16th, Melbourne

    $key = (string) Str::uuid();
    $one = $this->actingAs($u)->postJson('/logs/copy', ['from_date' => '2026-11-15', 'meal_slot' => 'dinner', 'batch_key' => $key])->assertCreated();
    expect($one->json('logs'))->toHaveCount(1)->and($one->json('logs.0.local_date'))->toBe('2026-11-16')->and($one->json('logs.0.source'))->toBe('copy');

    $whole = ['from_date' => '2026-11-15', 'batch_key' => (string) Str::uuid()];
    $this->actingAs($u)->postJson('/logs/copy', $whole)->assertCreated();
    $this->actingAs($u)->postJson('/logs/copy', $whole)->assertCreated();               // double tap, same batch key
    expect(FoodLog::where('local_date', '2026-11-16')->count())->toBe(3);              // 1 dinner + 2 from the whole-day copy, once

    $this->actingAs($u)->postJson('/logs/copy', ['from_date' => '2026-11-16', 'batch_key' => (string) Str::uuid()])->assertStatus(422);   // not onto itself
});

it('shows today with the plan day target, logs and totals inputs', function () {
    $u = loggedInUser();
    $this->actingAs($u)->postJson('/logs', ['items' => [item(egg(), 100)]]);

    $this->actingAs($u)->get('/')->assertInertia(fn (Assert $p) => $p
        ->component('Today')->where('isToday', true)->has('logs', 1)->where('logs.0.kcal', 143)
        ->where('target.protein', fn ($v) => $v > 100)->has('dayClass')->where('previous.count', 0));
});

it('shows a past day, and ignores a future or garbage date', function () {
    $u = loggedInUser();
    $this->actingAs($u)->postJson('/logs', ['local_date' => '2026-01-05', 'items' => [item(egg(), 100)]])->assertCreated();
    $this->actingAs($u)->get('/?date=2026-01-05')->assertInertia(fn (Assert $p) => $p->where('date', '2026-01-05')->where('isToday', false)->has('logs', 1));
    $this->actingAs($u)->get('/?date=2999-01-01')->assertInertia(fn (Assert $p) => $p->where('isToday', true));
    $this->actingAs($u)->get('/?date=garbage')->assertInertia(fn (Assert $p) => $p->where('isToday', true));
});

it('keeps one user\'s day private and sends a user with no profile to onboarding', function () {
    $a = loggedInUser();
    $this->actingAs($a)->postJson('/logs', ['items' => [item(egg(), 100)]]);
    $b = loggedInUser();
    $this->actingAs($b)->get('/')->assertInertia(fn (Assert $p) => $p->has('logs', 0));
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/onboarding');
});

it('serves frequent, recents and favourites from your own logs', function () {
    $u = loggedInUser();
    $a = egg(['name' => 'Alpha']);
    $b = egg(['name' => 'Bravo']);
    $this->actingAs($u)->postJson('/logs', ['items' => [item($a, 60), item($b, 30)]]);
    $this->actingAs($u)->postJson("/foods/$b/favourite")->assertOk()->assertJson(['favourite' => true]);

    $q = $this->actingAs($u)->getJson('/foods/quick')->assertOk()->json();
    expect(array_column($q['recents'], 'id'))->toContain($a, $b)
        ->and(array_column($q['frequent'], 'id'))->toContain($a, $b)
        ->and(array_column($q['favourites'], 'id'))->toBe([$b])
        ->and(collect($q['recents'])->firstWhere('id', $a)['last_grams'])->toEqual(60)
        ->and(collect($q['recents'])->firstWhere('id', $b)['is_favourite'])->toBeTrue();

    $this->actingAs($u)->postJson("/foods/$b/favourite")->assertJson(['favourite' => false]);
    expect($this->actingAs($u)->getJson('/foods/quick')->json('favourites'))->toBe([]);
    $this->actingAs(User::factory()->create())->getJson('/foods/quick')->assertJson(['frequent' => [], 'recents' => [], 'favourites' => []]);
});

it('requires login for every logging route', function () {
    $this->postJson('/logs', [])->assertUnauthorized();
    $this->getJson('/foods/quick')->assertUnauthorized();
    $this->postJson('/logs/copy', [])->assertUnauthorized();
});
