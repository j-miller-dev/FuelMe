<?php

use App\Domain\Progress\IntakeModel as I;

it('trusts a normally logged day as exact', function () {
    expect(I::day(3000, 0, null, 3000))->toBe(['state' => 'exact', 'kcal' => 3000.0, 'sd' => null]);
});

it('counts a day with almost nothing logged as missing, not as a very low intake', function () {
    expect(I::day(900, 0, null, 3000))->toBe(['state' => 'missing', 'kcal' => null, 'sd' => null]);
    expect(I::day(1200, 0, null, 3000)['state'])->toBe('exact');   // exactly 40%
});

it('uses a whole-day estimate with a wide error instead of skipping the day', function () {
    expect(I::day(0, 0, 3000, 3000))->toBe(['state' => 'rough', 'kcal' => 3000.0, 'sd' => 350.0]);
    // Breakfast logged, then "roughly on target" for the day: the estimate stands for the whole day, not on top of the breakfast.
    expect(I::day(600, 0, 3000, 3000)['kcal'])->toBe(3000.0);
});

it('lets a bigger real log win over a smaller estimate', function () {
    expect(I::day(3400, 0, 3000, 3000))->toBe(['state' => 'exact', 'kcal' => 3400.0, 'sd' => null]);
});

it('widens the error for rough meals, capped', function () {
    $one = I::day(3000, 900, null, 3000);   // a 900 kcal takeaway of 3000
    expect($one['state'])->toBe('exact')->and($one['sd'])->toEqualWithDelta(sqrt(150 ** 2 + 270 ** 2), 0.01);
    $most = I::day(3000, 3000, null, 3000);
    expect($most['state'])->toBe('rough')->and($most['sd'])->toBe(400.0);
});

it('never turns a rough day into a failure in the weekly score', function () {
    $week = [
        ['state' => 'exact', 'kcal' => 3050.0, 'target' => 3000.0], ['state' => 'exact', 'kcal' => 2500.0, 'target' => 3000.0],
        ['state' => 'rough', 'kcal' => 3000.0, 'target' => 3000.0], ['state' => 'missing', 'kcal' => null, 'target' => 3000.0],
        ['state' => 'missing', 'kcal' => null, 'target' => 3000.0], ['state' => 'exact', 'kcal' => 3300.0, 'target' => 3000.0],
        ['state' => 'rough', 'kcal' => 3000.0, 'target' => 3000.0],
    ];
    // 5 of 7 counted; of the 3 exact days only 2 are within 15% (2500 is 16.7% under); rough days are not used to flatter the rate.
    expect(I::consistency($week))->toBe(['counted' => 5, 'total' => 7, 'exact' => 3, 'within15' => 2]);
});
