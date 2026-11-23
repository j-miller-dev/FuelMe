<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function takeCreatine($test, $u, ?string $key = null, array $extra = [])
{
    return $test->actingAs($u)->postJson('/supplements', ['name' => 'creatine', 'idempotency_key' => $key ?? (string) Str::uuid()] + $extra);
}

it('logs a 5 g creatine dose in one tap, once even if the tap is replayed', function () {
    $u = loggedInUser();
    $key = (string) Str::uuid();
    takeCreatine($this, $u, $key)->assertCreated()->assertJsonPath('takenG', 5)->assertJsonPath('daysOfLast7', 1);
    takeCreatine($this, $u, $key)->assertCreated()->assertJsonPath('takenG', 5);   // replay: still one dose
    expect(DB::table('supplement_logs')->where('user_id', $u->id)->count())->toBe(1);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('creatine.takenG', 5)->where('creatine.defaultG', 5)->where('creatine.daysOfLast7', 1));
});

it('undoes the last dose, and only your own', function () {
    $u = loggedInUser();
    $id = takeCreatine($this, $u)->json('lastId');
    $this->actingAs(loggedInUser())->deleteJson("/supplements/{$id}")->assertNotFound();
    $this->actingAs($u)->deleteJson("/supplements/{$id}")->assertOk()->assertJsonPath('takenG', 0)->assertJsonPath('lastId', null);
    expect(DB::table('supplement_logs')->count())->toBe(0);
});

it('counts days taken in the last seven, not a streak, and accepts a recent past day', function () {
    $u = loggedInUser();
    $today = $this->actingAs($u)->get('/')->viewData('page')['props']['date'];
    foreach ([0, 2, 3] as $back) {
        takeCreatine($this, $u, null, ['local_date' => date('Y-m-d', strtotime($today . " -{$back} days"))])->assertCreated();
    }
    takeCreatine($this, $u, null, ['local_date' => date('Y-m-d', strtotime($today . ' -10 days'))])->assertCreated();   // outside the 7 days
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('creatine.daysOfLast7', 3));
});

it('rejects unknown supplements, silly doses, future and very old dates', function () {
    $u = loggedInUser();
    $k = fn () => (string) Str::uuid();
    $this->actingAs($u)->postJson('/supplements', ['name' => 'mystery', 'idempotency_key' => $k()])->assertStatus(422);
    takeCreatine($this, $u, null, ['amount_g' => 50])->assertStatus(422);
    takeCreatine($this, $u, null, ['local_date' => date('Y-m-d', strtotime('+3 days'))])->assertStatus(422);
    takeCreatine($this, $u, null, ['local_date' => date('Y-m-d', strtotime('-90 days'))])->assertStatus(422);
    $this->app['auth']->forgetGuards();
    $this->postJson('/supplements', ['name' => 'creatine', 'idempotency_key' => $k()])->assertUnauthorized();
});

it('never touches the food log or the calorie budget', function () {
    $u = loggedInUser();
    $before = DB::table('food_logs')->count();
    takeCreatine($this, $u)->assertCreated();
    expect(DB::table('food_logs')->count())->toBe($before);
});

it('counts the last 28 days separately from the last 7', function () {
    $u = loggedInUser();
    $today = $this->actingAs($u)->get('/')->viewData('page')['props']['date'];
    foreach ([0, 10, 20, 27, 40] as $back) {
        takeCreatine($this, $u, null, ['local_date' => date('Y-m-d', strtotime($today . " -{$back} days"))]);
    }
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('creatine.daysOfLast7', 1)->where('creatine.daysOfLast28', 4));
});

it('saves your plan, loads in 5 g serves, and then tells you your daily dose', function () {
    $u = loggedInUser();
    $today = $this->actingAs($u)->get('/')->viewData('page')['props']['date'];
    $this->actingAs($u)->putJson('/supplements/creatine-plan', ['plan' => ['mode' => 'loading', 'start' => $today, 'loading_days' => 5, 'maintenance_g' => 4]])
        ->assertOk()->assertJsonPath('status.phase', 'loading')->assertJsonPath('serveG', 5)->assertJsonPath('hasPlan', true);
    $r = takeCreatine($this, $u)->assertCreated();
    expect($r->json('takenG'))->toBe(5)->and($r->json('status.targetG'))->toBeGreaterThanOrEqual(10)->and($r->json('status.saturated'))->toBeFalse();

    // pretend four earlier loading days were completed, then finish today's dose
    $target = $r->json('status.targetG');
    foreach ([1, 2, 3, 4] as $back) {
        DB::table('supplement_logs')->insert(['user_id' => $u->id, 'name' => 'creatine', 'local_date' => date('Y-m-d', strtotime($today . " -{$back} days")), 'amount_g' => $target, 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($u)->putJson('/supplements/creatine-plan', ['plan' => ['mode' => 'loading', 'start' => date('Y-m-d', strtotime($today . ' -4 days')), 'loading_days' => 5, 'maintenance_g' => 4]])->assertOk();
    $last = DB::table('supplement_logs')->where('user_id', $u->id)->where('local_date', $today)->first();
    DB::table('supplement_logs')->where('id', $last->id)->update(['amount_g' => $target]);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('creatine.status.saturated', true)->where('creatine.status.continueG', 4)->where('creatine.status.phase', 'saturated'));
    // once saturated, a tap logs the daily dose, not a loading serve
    expect(takeCreatine($this, $u)->json('serveG'))->toBe(4);
});

it('rejects a bad plan and can reset to the default', function () {
    $u = loggedInUser();
    $today = $this->actingAs($u)->get('/')->viewData('page')['props']['date'];
    $this->actingAs($u)->putJson('/supplements/creatine-plan', ['plan' => ['mode' => 'loading', 'start' => $today, 'loading_days' => 14, 'maintenance_g' => 5]])->assertStatus(422);
    $this->actingAs($u)->putJson('/supplements/creatine-plan', ['plan' => ['mode' => 'maintenance', 'start' => $today, 'loading_days' => 5, 'maintenance_g' => 3.5]])->assertOk()->assertJsonPath('serveG', 3.5);
    $this->actingAs($u)->deleteJson('/supplements/creatine-plan')->assertOk()->assertJsonPath('hasPlan', false)->assertJsonPath('serveG', 5);
    $other = loggedInUser();
    $this->actingAs($other)->get('/')->assertInertia(fn ($p) => $p->where('creatine.hasPlan', false));
});
