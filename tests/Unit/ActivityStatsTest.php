<?php

use App\Domain\Watch\ActivityStats;

it('works out pace for runs and speed for rides, and nothing for gym sessions', function () {
    // 8 km in 40 min = 5:00 per km
    expect(ActivityStats::rate('Run', 8000, 2400))->toBe(['kind' => 'pace', 'value' => 300.0])
        // 30 km in 1 h = 30 km/h
        ->and(ActivityStats::rate('Ride', 30000, 3600))->toBe(['kind' => 'speed', 'value' => 30.0])
        ->and(ActivityStats::rate('WeightTraining', 0, 3600))->toBeNull()
        ->and(ActivityStats::rate('Run', null, 3600))->toBeNull();
});

it('drops a pace or speed nobody could produce, rather than showing a GPS glitch', function () {
    expect(ActivityStats::rate('Run', 5000, 300))->toBeNull()      // 1:00 per km
        ->and(ActivityStats::rate('Ride', 90000, 3600))->toBeNull() // 90 km/h
        ->and(ActivityStats::rate('Run', 50, 600))->toBeNull();     // 50 m is not a run
});

it('groups into months, newest first, with distance, count and minutes', function () {
    $rows = [
        ['date' => '2026-05-28', 'type' => 'Run', 'minutes' => 70, 'distanceM' => 8400, 'secs' => 4200],
        ['date' => '2026-05-28', 'type' => 'Run', 'minutes' => 10, 'distanceM' => 1140, 'secs' => 635],
        ['date' => '2025-12-11', 'type' => 'Ride', 'minutes' => 8, 'distanceM' => 280, 'secs' => 473],
        ['date' => '2026-05-03', 'type' => 'WeightTraining', 'minutes' => 40, 'distanceM' => null, 'secs' => 2400],
    ];
    $m = ActivityStats::months($rows);

    expect(array_column($m, 'month'))->toBe(['2026-05', '2025-12'])
        ->and($m[0]['count'])->toBe(3)->and($m[0]['km'])->toBe(9.54)->and($m[0]['minutes'])->toBe(120)
        ->and($m[0]['items'][2]['rate'])->toBeNull()
        ->and($m[0]['items'][0]['rate']['value'])->toBe(500.0)
        ->and($m[1]['km'])->toBe(0.28);
});
