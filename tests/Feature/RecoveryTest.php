<?php

use App\Services\Coach\CoachTools;
use App\Services\DeloadService;
use App\Services\WatchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function recToday($u): string
{
    return \App\Domain\Foods\LocalDay::of(now(), $u->profile->tz, (int) $u->profile->rollover_hour);
}

function recSeed($u, int $baselineDays = 40): void
{
    $today = recToday($u);
    for ($i = 0; $i < $baselineDays; $i++) {
        $recent = $i < 7;
        DB::table('watch_days')->insert(['user_id' => $u->id, 'local_date' => date('Y-m-d', strtotime($today . " -{$i} days")), 'sleep_secs' => $recent ? 5 * 3600 : 8 * 3600,
            'resting_hr' => $recent ? 60 : ($i % 2 ? 50 : 54), 'hrv' => $recent ? 40 : ($i % 2 ? 60 : 70), 'steps' => 8000, 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('shows nothing on Today until a watch has synced', function () {
    $u = loggedInUser();

    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('recovery', null));
    $this->actingAs($u)->get('/recovery')->assertOk()->assertInertia(fn ($p) => $p->component('Recovery')->where('data.connected', false)->where('data.signal.level', 'unknown'));
});

it('reads a clear drop in HRV, a higher resting HR and short sleep as red, with the reasons', function () {
    $u = loggedInUser();
    recSeed($u);

    $d = $this->actingAs($u)->get('/recovery')->viewData('page')['props']['data'];

    expect($d['signal']['level'])->toBe('red')->and($d['signal']['flagCount'] ?? count($d['signal']['flags']))->toBeGreaterThanOrEqual(2)
        ->and($d['hrv']['status'])->toBe('below')->and($d['rhr']['status'])->toBe('above')->and($d['sleep']['performancePct'])->toBe(63);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('recovery.level', 'red')->has('recovery.reasons', 2));
});

it('lets you set a sleep target, and rejects an absurd one', function () {
    $u = loggedInUser();
    recSeed($u);

    $this->actingAs($u)->put('/recovery/sleep-target', ['hours' => 7.5])->assertRedirect();
    expect($u->profile()->first()->sleep_target_min)->toBe(450);
    $this->actingAs($u)->put('/recovery/sleep-target', ['hours' => 2])->assertSessionHasErrors('hours');
});

it('labels a week as a deload and takes it back', function () {
    $u = loggedInUser();
    $today = recToday($u);

    $this->actingAs($u)->post('/recovery/deload', ['date' => $today, 'on' => true])->assertRedirect();
    $monday = \App\Domain\Training\Deload::mondayOf($today);
    expect(app(DeloadService::class)->mondays($u))->toBe([$monday])->and(app(DeloadService::class)->isDeload($u, date('Y-m-d', strtotime($monday . ' +6 days'))))->toBeTrue()
        ->and(app(DeloadService::class)->isDeload($u, date('Y-m-d', strtotime($monday . ' +7 days'))))->toBeFalse();
    $this->actingAs($u)->post('/recovery/deload', ['date' => $today, 'on' => true]);   // twice is still one week
    expect(DB::table('deload_weeks')->count())->toBe(1);

    $this->actingAs($u)->post('/recovery/deload', ['date' => $today, 'on' => false])->assertRedirect();
    expect(DB::table('deload_weeks')->count())->toBe(0);
});

it('does not flag a high load in a deload week, and says so on Today', function () {
    $u = loggedInUser();
    recSeed($u);
    $today = recToday($u);
    for ($i = 7; $i < 35; $i++) {
        DB::table('workout_logs')->insert(['user_id' => $u->id, 'local_date' => date('Y-m-d', strtotime($today . " -{$i} days")), 'type' => 'lifting', 'intensity' => 'hit', 'name' => 'x', 'minutes' => 30, 'met' => 3.5, 'net_kcal' => 100, 'rpe' => 5, 'status' => 'done', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('workout_logs')->insert(['user_id' => $u->id, 'local_date' => $today, 'type' => 'bjj', 'intensity' => 'hard', 'name' => 'x', 'minutes' => 600, 'met' => 8, 'net_kcal' => 300, 'rpe' => 9, 'status' => 'done', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    $before = $this->actingAs($u)->get('/recovery')->viewData('page')['props']['data'];
    expect(implode(' ', $before['signal']['flags']))->toContain('training load');

    $this->actingAs($u)->post('/recovery/deload', ['date' => $today, 'on' => true]);
    $after = $this->actingAs($u)->get('/recovery')->viewData('page')['props']['data'];
    expect(implode(' ', $after['signal']['flags']))->not->toContain('training load')->and($after['deload']['now'])->toBeTrue();
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('recovery.deload', true));
});

it('uses Polar when both watches recorded a session, and says which others recorded it', function () {
    $u = loggedInUser();
    $d = Carbon::now('UTC')->subDay()->toDateString();
    $row = fn (string $id, string $time, string $src, int $min = 40) => ['user_id' => $u->id, 'ext_id' => $id, 'local_date' => $d, 'start_local' => "$d $time", 'name' => "$src run", 'type' => 'Run', 'minutes' => $min, 'avg_hr' => 150, 'load' => 60, 'source' => $src, 'created_at' => now(), 'updated_at' => now()];
    DB::table('watch_activities')->insert([$row('a', '06:02:00', 'COROS'), $row('b', '06:00:00', 'POLAR'), $row('c', '18:00:00', 'COROS')]);

    $acts = app(WatchService::class)->activities($u);

    expect(array_column($acts, 'source'))->toEqualCanonicalizing(['COROS', 'POLAR'])->and(count($acts))->toBe(2);
    $polar = collect($acts)->firstWhere('source', 'POLAR');
    expect($polar['alsoRecordedBy'])->toBe(['COROS']);
    expect(DB::table('watch_activities')->count())->toBe(3);   // nothing was deleted
});

it('leaves a deload week out of stall checks', function () {
    $u = loggedInUser();
    $svc = app(\App\Services\Hevy\StrengthService::class);
    $mon = \App\Domain\Training\Deload::mondayOf(now($u->profile->tz)->toDateString());
    DB::table('hevy_connections')->insert(['user_id' => $u->id, 'api_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    // a deload session (light) this week, normal sessions in earlier weeks
    foreach ([[$mon, 60], [date('Y-m-d', strtotime($mon . ' -7 days')), 100], [date('Y-m-d', strtotime($mon . ' -14 days')), 100], [date('Y-m-d', strtotime($mon . ' -21 days')), 100]] as $i => [$date, $kg]) {
        $id = DB::table('hevy_workouts')->insertGetId(['user_id' => $u->id, 'hevy_id' => "w$i", 'title' => 'Legs', 'start_at' => $date . ' 06:00:00', 'local_date' => $date, 'minutes' => 40, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hevy_sets')->insert(['hevy_workout_id' => $id, 'exercise_index' => 0, 'exercise_title' => 'Leg Press', 'template_id' => 'LP1', 'set_index' => 0, 'type' => 'normal', 'weight_kg' => $kg, 'reps' => 8]);
    }
    $before = collect($svc->overview($u)['exercises'])->firstWhere('id', 'LP1');
    expect($before['status'])->toBe('down');   // 60 kg after 100 kg looks like a drop

    app(DeloadService::class)->set($u, $mon, true);
    $after = $svc->overview($u);
    $ex = collect($after['exercises'])->firstWhere('id', 'LP1');
    expect($after['deload_now'])->toBeTrue()->and($ex['status'])->not->toBe('down')->and($after['recent'][0]['deload'])->toBeTrue();
});

it('gives the coach a recovery tool that is empty without watch data', function () {
    $u = loggedInUser();
    expect(app(CoachTools::class)->run('get_recovery', [], $u))->toMatchArray(['connected' => false]);
    recSeed($u);
    $out = app(CoachTools::class)->run('get_recovery', [], $u);
    expect($out['signal']['level'])->toBe('red')->and($out)->toHaveKey('assumptions');
});

it('compares watch sleep and HRV with how you felt, only once there are 8 matching mornings', function () {
    $u = loggedInUser();
    $today = recToday($u);
    $mk = function (int $n) use ($u, $today) {
        for ($i = 1; $i <= $n; $i++) {
            $d = date('Y-m-d', strtotime($today . " -{$i} days"));
            DB::table('watch_days')->insert(['user_id' => $u->id, 'local_date' => $d, 'sleep_secs' => (5 + $i % 5) * 3600, 'hrv' => 40 + $i * 3, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('readiness_logs')->insert(['user_id' => $u->id, 'local_date' => $d, 'sleep' => 3, 'soreness' => 2, 'energy' => min(5, 1 + $i % 5), 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $mk(7);
    expect(app(\App\Services\ReadinessService::class)->insights($u))->toBe([]);   // 7 pairs is not enough

    DB::table('watch_days')->delete();
    DB::table('readiness_logs')->delete();
    $mk(12);
    $keys = array_column(app(\App\Services\ReadinessService::class)->insights($u), 'key');
    expect($keys)->toContain('watch_sleep_energy')->and($keys)->toContain('hrv_energy');
});
