<?php

use Illuminate\Support\Facades\DB;

it('records a busy day from the day target, never from a client number', function () {
    $u = loggedInUser();
    $target = $this->actingAs($u)->get('/')->viewData('page')['props']['target']['kcal'];

    $this->actingAs($u)->postJson('/days/estimate', ['kind' => 'on_target', 'kcal' => 99999])->assertCreated()
        ->assertJsonPath('saved.kind', 'on_target');

    $row = DB::table('day_estimates')->where('user_id', $u->id)->first();
    expect((int) round((float) $row->kcal))->toBe($target);
    $props = $this->actingAs($u)->get('/')->viewData('page')['props'];
    expect($props['estimate']['kind'])->toBe('on_target')->and($props['estimate']['kcal'])->toBe($target);
});

it('replaces the estimate for the same day, and can undo it', function () {
    $u = loggedInUser();
    $this->actingAs($u)->postJson('/days/estimate', ['kind' => 'over'])->assertCreated();
    $this->actingAs($u)->postJson('/days/estimate', ['kind' => 'under'])->assertCreated();
    expect(DB::table('day_estimates')->where('user_id', $u->id)->count())->toBe(1)
        ->and(DB::table('day_estimates')->where('user_id', $u->id)->value('kind'))->toBe('under');

    $date = DB::table('day_estimates')->where('user_id', $u->id)->value('local_date');
    $this->actingAs($u)->deleteJson("/days/{$date}/estimate")->assertOk();
    expect(DB::table('day_estimates')->where('user_id', $u->id)->count())->toBe(0);
});

it('rejects a bad kind and a future date', function () {
    $u = loggedInUser();
    $this->actingAs($u)->postJson('/days/estimate', ['kind' => 'huge'])->assertStatus(422);
    $this->actingAs($u)->postJson('/days/estimate', ['kind' => 'under', 'local_date' => date('Y-m-d', strtotime('+3 days'))])->assertStatus(422);
});

it('flags a food entry as rough only for quick adds', function () {
    $u = loggedInUser();
    $res = $this->actingAs($u)->postJson('/logs', ['items' => [['source' => 'quick', 'name' => 'Takeaway', 'kcal' => 900, 'protein' => 30, 'carbs' => 90, 'fat' => 40, 'rough' => true, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'meal_slot' => 'dinner']]]);
    $res->assertSuccessful();
    expect((bool) DB::table('food_logs')->where('user_id', $u->id)->value('is_rough'))->toBeTrue();
});
