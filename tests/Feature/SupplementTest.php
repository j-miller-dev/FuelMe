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
