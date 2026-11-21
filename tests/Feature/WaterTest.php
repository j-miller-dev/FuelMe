<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('logs drinks, totals them on Today and ignores a retried tap', function () {
    $u = loggedInUser();
    $key = (string) Str::uuid();
    $this->actingAs($u)->postJson('/water', ['ml' => 500, 'idempotency_key' => $key])->assertCreated()->assertJsonPath('ml', 500);
    $this->actingAs($u)->postJson('/water', ['ml' => 500, 'idempotency_key' => $key])->assertCreated()->assertJsonPath('ml', 500);
    $this->actingAs($u)->postJson('/water', ['ml' => 250, 'idempotency_key' => (string) Str::uuid()])->assertJsonPath('ml', 750);

    $w = $this->actingAs($u)->get('/')->viewData('page')['props']['water'];
    expect($w['ml'])->toBe(750)->and($w['targetMl'])->toBe($u->profile->sex === 'female' ? 2100 : 2600)->and($w['lastId'])->not->toBeNull();
});

it('undoes the last drink and cannot touch someone else\'s', function () {
    $u = loggedInUser();
    $r = $this->actingAs($u)->postJson('/water', ['ml' => 300, 'idempotency_key' => (string) Str::uuid()]);
    $id = $r->json('lastId');
    $this->actingAs($u)->deleteJson("/water/{$id}")->assertOk()->assertJsonPath('ml', 0);
    $this->actingAs($u)->deleteJson("/water/{$id}")->assertNotFound();
    expect(DB::table('water_logs')->count())->toBe(0);
});

it('rejects silly amounts', function () {
    $u = loggedInUser();
    $this->actingAs($u)->postJson('/water', ['ml' => 20, 'idempotency_key' => (string) Str::uuid()])->assertStatus(422);
    $this->actingAs($u)->postJson('/water', ['ml' => 9000, 'idempotency_key' => (string) Str::uuid()])->assertStatus(422);
});

it('gives the next session a clock-time plan with fluids, meals and an after step', function () {
    $u = loggedInUser();
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-23 05:00', 'Australia/Melbourne'));   // a Monday with a 06:30 session
    $f = $this->actingAs($u)->get('/')->viewData('page')['props']['training']['fuel'];
    expect($f['when'])->toBe('today')->and($f['timeline'])->not->toBeNull()
        ->and(collect($f['timeline'])->pluck('kind')->all())->toContain('fluid', 'meal', 'session', 'after');
    $meal = collect($f['timeline'])->firstWhere('kind', 'meal');
    expect($meal['at'])->toBe('3:30 am')->and($meal['inMin'])->toBe(-90);   // already passed: the card says so and points to the nearest option
});
