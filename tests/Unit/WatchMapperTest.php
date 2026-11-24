<?php

use App\Domain\Watch\WatchMapper;

it('maps a wellness day and drops values a body or watch cannot produce', function () {
    expect(WatchMapper::wellness(['id' => '2026-11-22', 'sleepSecs' => 25200, 'restingHR' => 52, 'hrv' => 64.26, 'steps' => 9100]))
        ->toBe(['local_date' => '2026-11-22', 'sleep_secs' => 25200, 'resting_hr' => 52, 'hrv' => 64.3, 'steps' => 9100]);

    $odd = WatchMapper::wellness(['id' => '2026-11-22', 'sleepSecs' => 600, 'restingHR' => 7, 'hrv' => 900, 'steps' => 250000, 'weight' => 80]);
    expect($odd)->toBeNull();   // nothing believable left, so no row

    $partial = WatchMapper::wellness(['id' => '2026-11-22', 'sleepSecs' => 0, 'restingHR' => 55]);
    expect($partial['sleep_secs'])->toBeNull()->and($partial['resting_hr'])->toBe(55);   // 0 s of sleep means "not recorded", never "zero sleep"
});

it('ignores wellness rows without a real date', function () {
    expect(WatchMapper::wellness(['restingHR' => 50]))->toBeNull()->and(WatchMapper::wellness(['id' => 'garbage', 'restingHR' => 50]))->toBeNull();
});

it('maps an activity from its local start time, and skips ones with no data', function () {
    $a = WatchMapper::activity(['id' => 'i123', 'start_date_local' => '2026-11-23T06:30:00', 'name' => 'Run', 'type' => 'Run', 'moving_time' => 2705, 'distance' => 8423.6, 'average_heartrate' => 151.4, 'max_heartrate' => 178, 'icu_training_load' => 62, 'source' => 'COROS']);
    expect($a)->toBe(['ext_id' => 'i123', 'local_date' => '2026-11-23', 'start_local' => '2026-11-23 06:30:00', 'name' => 'Run', 'type' => 'Run', 'minutes' => 45, 'distance_m' => 8424, 'moving_secs' => 2705, 'avg_hr' => 151, 'max_hr' => 178, 'load' => 62, 'source' => 'COROS']);

    expect(WatchMapper::activity(['id' => 'i9', '_note' => 'STRAVA activities are not available via the API']))->toBeNull();
    $bad = WatchMapper::activity(['id' => 'i10', 'start_date_local' => '2026-11-23T06:30:00', 'average_heartrate' => 400, 'max_heartrate' => 5]);
    expect($bad['avg_hr'])->toBeNull()->and($bad['max_hr'])->toBeNull()->and($bad['name'])->toBe('Activity');
});
