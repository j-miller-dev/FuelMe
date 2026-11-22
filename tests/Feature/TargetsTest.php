<?php

use Illuminate\Support\Facades\DB;

it('shows the recommendation and your numbers side by side', function () {
    $u = loggedInUser();
    $this->actingAs($u)->get('/targets')->assertInertia(fn ($page) => $page->component('Targets')
        ->where('overrides.kcal_offset', 0)->where('overrides.protein_g_override', null)
        ->whereAll(['recommended.kcal' => fn ($v) => $v > 0])
        ->where('style', 'hit'));
});

it('layers your protein, energy and fat overrides on top without touching the recommendation', function () {
    $u = loggedInUser();
    $before = $this->actingAs($u)->get('/targets')->viewData('page')['props'];

    $this->actingAs($u)->put('/targets', ['protein_g_override' => 190, 'kcal_offset' => 150, 'fat_min_g_per_kg' => 1.1])->assertRedirect('/targets');

    $after = $this->actingAs($u)->get('/targets')->viewData('page')['props'];
    expect($after['recommended'])->toBe($before['recommended'])                 // the recommendation is untouched
        ->and($after['yours']['protein'])->toBe(190)                            // grams you asked for
        ->and($after['yours']['kcal'])->toBeGreaterThan($before['recommended']['kcal'])
        ->and($after['yours']['fat'])->toBeGreaterThanOrEqual($before['recommended']['fat'])
        ->and($after['overrides']['kcal_offset'])->toBe(150);
});

it('uses your override on Today, and blank goes back to the recommendation', function () {
    $u = loggedInUser();
    $rec = $this->actingAs($u)->get('/')->viewData('page')['props']['target'];
    $this->actingAs($u)->put('/targets', ['protein_g_override' => 200, 'kcal_offset' => 0])->assertRedirect();
    expect($this->actingAs($u)->get('/')->viewData('page')['props']['target']['protein'])->toBe(200);

    $this->actingAs($u)->put('/targets', ['protein_g_override' => null, 'kcal_offset' => 0])->assertRedirect();
    expect($this->actingAs($u)->get('/')->viewData('page')['props']['target']['protein'])->toBe($rec['protein']);
});

it('says so when your protein is capped by the calorie target', function () {
    $u = loggedInUser();
    $this->actingAs($u)->put('/targets', ['protein_g_override' => 400])->assertRedirect();
    $codes = collect($this->actingAs($u)->get('/targets')->viewData('page')['props']['warnings'])->pluck('code');
    expect($codes)->toContain('protein_capped');
});

it('rejects values that cannot be right and stores sensible tolerances', function () {
    $u = loggedInUser();
    $this->actingAs($u)->put('/targets', ['protein_g_override' => 5])->assertSessionHasErrors('protein_g_override');
    $this->actingAs($u)->put('/targets', ['kcal_offset' => 99999])->assertSessionHasErrors('kcal_offset');
    $this->actingAs($u)->put('/targets', ['hit_rules' => ['kcal_pct' => 12]])->assertRedirect();
    expect($this->actingAs($u)->get('/targets')->viewData('page')['props']['hitRules'])->toMatchArray(['kcal_pct' => 12.0, 'protein_min_pct' => 95.0]);
});

it('feeds the last-5-days strip, with unlogged days left unjudged', function () {
    $u = loggedInUser();
    $strip = $this->actingAs($u)->get('/')->viewData('page')['props']['strip'];
    expect($strip)->toHaveKeys(['days', 'summary'])
        ->and(count($strip['days']))->toBeLessThanOrEqual(5)
        ->and($strip['summary']['onTarget'])->toBe(0);
    foreach ($strip['days'] as $d) {
        expect($d['logged'])->toBeFalse()->and($d['hits'])->toBeNull();   // nothing logged yet = not judged, never a miss
    }
});

it('saves your own serving for a shared food', function () {
    $u = loggedInUser();
    $id = egg();
    $this->actingAs($u)->postJson("/foods/{$id}/servings", ['label' => '1 egg', 'grams' => 50])->assertOk()
        ->assertJsonPath('servings.0.label', '1 egg')->assertJsonPath('servings.0.own', true)->assertJsonPath('servings.0.grams', 50);
    // Saving the same name again changes the grams, it does not duplicate.
    $this->actingAs($u)->postJson("/foods/{$id}/servings", ['label' => '1 egg', 'grams' => 55])->assertOk()->assertJsonCount(1, 'servings')->assertJsonPath('servings.0.grams', 55);
});

it('offers your serving in search next time, and only to you', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Food search needs PostgreSQL.');
    }
    $u = loggedInUser();
    $id = egg();
    $this->actingAs($u)->postJson("/foods/{$id}/servings", ['label' => '1 egg', 'grams' => 50])->assertOk();

    $mine = collect($this->actingAs($u)->getJson('/foods/search?q=egg')->json('results'))->firstWhere('id', $id);
    expect(collect($mine['servings'])->firstWhere('label', '1 egg')['grams'])->toEqual(50);

    $other = loggedInUser();
    $theirs = collect($this->actingAs($other)->getJson('/foods/search?q=egg')->json('results'))->firstWhere('id', $id);
    expect(collect($theirs['servings'])->pluck('label'))->not->toContain('1 egg');
});

it('removes only your own serving', function () {
    $u = loggedInUser();
    $id = egg();
    $sid = $this->actingAs($u)->postJson("/foods/{$id}/servings", ['label' => '1 egg', 'grams' => 50])->json('servings.0.id');
    $other = loggedInUser();
    $this->actingAs($other)->deleteJson("/foods/servings/{$sid}")->assertNotFound();
    $this->actingAs($u)->deleteJson("/foods/servings/{$sid}")->assertOk()->assertJsonCount(0, 'servings');
});

it('validates a serving', function () {
    $u = loggedInUser();
    $id = egg();
    $this->actingAs($u)->postJson("/foods/{$id}/servings", ['label' => '', 'grams' => 50])->assertUnprocessable();
    $this->actingAs($u)->postJson("/foods/{$id}/servings", ['label' => 'big', 'grams' => 0])->assertUnprocessable();
});
