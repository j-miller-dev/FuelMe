<?php

use App\Jobs\SyncIntervals;
use App\Services\Intervals\IntervalsSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const ICU_KEY = 'icu-test-key-1234567890';

function icuFake(array $wellness = [], array $activities = [], int $athleteStatus = 200): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        '*/wellness*' => Http::response($wellness),
        '*/activities*' => Http::response($activities),
        '*/api/v1/athlete/0' => Http::response(['id' => 'i1', 'name' => 'x'], $athleteStatus),
    ]);
}

function icuConnect($test, $u): void
{
    icuFake();
    Bus::fake();
    $test->actingAs($u)->post('/watch/connect', ['api_key' => ICU_KEY])->assertRedirect();
}

it('stores the key encrypted and never sends it back to the browser', function () {
    $u = loggedInUser();
    icuConnect($this, $u);

    $conn = DB::table('intervals_connections')->where('user_id', $u->id)->first();
    expect($conn->api_key)->not->toContain(ICU_KEY);
    $html = $this->actingAs($u)->get('/watch')->assertOk()->getContent();
    expect($html)->not->toContain(ICU_KEY);
    Bus::assertDispatched(SyncIntervals::class);
});

it('says so plainly when Intervals.icu rejects the key, and keeps nothing', function () {
    $u = loggedInUser();
    icuFake([], [], 401);

    $this->actingAs($u)->post('/watch/connect', ['api_key' => 'wrong-key-1234567890'])->assertSessionHasErrors(['api_key' => 'Intervals.icu did not accept that API key.']);
    expect(DB::table('intervals_connections')->count())->toBe(0);
});

it('syncs wellness and activities, and a second run does not duplicate', function () {
    $u = loggedInUser();
    icuConnect($this, $u);
    $d = Carbon::now($u->profile->tz)->subDay()->toDateString();
    icuFake([['id' => $d, 'sleepSecs' => 26400, 'restingHR' => 51, 'hrv' => 70, 'steps' => 8000]],
        [['id' => 'i1', 'start_date_local' => $d . 'T06:00:00', 'name' => 'Run', 'type' => 'Run', 'moving_time' => 1800, 'average_heartrate' => 150, 'source' => 'COROS'],
         ['id' => 'i2', 'start_date_local' => $d . 'T18:00:00', 'name' => 'Ride', 'type' => 'Ride', 'moving_time' => 3600, 'source' => 'POLAR']]);

    $r = app(IntervalsSync::class)->sync($u->fresh());
    app(IntervalsSync::class)->sync($u->fresh());

    expect($r)->toMatchArray(['ok' => true, 'days' => 1, 'activities' => 2])
        ->and(DB::table('watch_days')->count())->toBe(1)->and(DB::table('watch_activities')->count())->toBe(2);
    $page = $this->actingAs($u)->get('/watch')->viewData('page')['props'];
    expect($page['days'][0])->toMatchArray(['sleepMin' => 440, 'restingHr' => 51, 'hrv' => 70.0, 'steps' => 8000])->and($page['connection']['last_error'])->toBeNull();
});

it('records the error instead of throwing when Intervals.icu fails', function () {
    $u = loggedInUser();
    icuConnect($this, $u);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*' => Http::response('nope', 500)]);

    $r = app(IntervalsSync::class)->sync($u->fresh());

    expect($r['ok'])->toBeFalse()->and(DB::table('intervals_connections')->value('last_error'))->toContain('error (500)');
});

it('shows last night from the watch on Today, and keeps users apart', function () {
    $u = loggedInUser();
    icuConnect($this, $u);
    $today = Carbon::now($u->profile->tz)->subHours($u->profile->rollover_hour)->toDateString();
    DB::table('watch_days')->insert(['user_id' => $u->id, 'local_date' => $today, 'sleep_secs' => 27000, 'resting_hr' => 49, 'hrv' => 80, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('readiness.watch.sleepMin', 450)->where('readiness.watch.restingHr', 49));

    $other = \App\Models\User::factory()->create();
    expect(app(\App\Services\WatchService::class)->day($other, $today))->toBeNull();
    $this->actingAs($other)->post('/watch/sync')->assertNotFound();
});

it('disconnects, and deletes the synced data only when asked', function () {
    $u = loggedInUser();
    icuConnect($this, $u);
    DB::table('watch_days')->insert(['user_id' => $u->id, 'local_date' => '2026-11-20', 'steps' => 5000, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($u)->delete('/watch/connection')->assertRedirect();
    expect(DB::table('intervals_connections')->count())->toBe(0)->and(DB::table('watch_days')->count())->toBe(1);

    icuConnect($this, $u);
    $this->actingAs($u)->delete('/watch/connection?delete_data=1')->assertRedirect();
    expect(DB::table('watch_days')->count())->toBe(0);
});
