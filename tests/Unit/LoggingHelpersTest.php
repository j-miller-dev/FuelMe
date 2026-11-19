<?php

use App\Domain\Foods\Frequent;
use App\Domain\Foods\LocalDay;
use App\Domain\Foods\Portion;

// ---- LocalDay: the day rolls over at 03:00 local, not midnight and not UTC -------------------------------------------

it('assigns late-night eating to the previous day', function (string $utc, string $expected) {
    expect(LocalDay::of(new DateTimeImmutable($utc, new DateTimeZone('UTC')), 'Australia/Melbourne', 3))->toBe($expected);
})->with([
    // Melbourne is UTC+11 in November (AEDT)
    '01:30 Tue local is still Monday' => ['2026-11-16 14:30:00', '2026-11-16'],   // 2026-11-17 01:30 local
    '03:00 Tue local starts Tuesday' => ['2026-11-16 16:00:00', '2026-11-17'],
    'midday Tue local' => ['2026-11-17 01:00:00', '2026-11-17'],
    '23:59 Mon local' => ['2026-11-16 12:59:00', '2026-11-16'],
]);

it('respects daylight saving and a different rollover hour', function () {
    // Winter: Melbourne is UTC+10. 02:30 local on 1 July is still 30 June with a 03:00 rollover, but 1 July with a 02:00 one.
    $t = new DateTimeImmutable('2026-06-30 16:30:00', new DateTimeZone('UTC'));
    expect(LocalDay::of($t, 'Australia/Melbourne', 3))->toBe('2026-06-30')->and(LocalDay::of($t, 'Australia/Melbourne', 2))->toBe('2026-07-01');
});

it('gives the weekday key of a date', function () {
    expect(LocalDay::dayKey('2026-11-16'))->toBe('mon')->and(LocalDay::dayKey('2026-11-22'))->toBe('sun');
});

// ---- Portion ---------------------------------------------------------------------------------------------------------

it('computes macros for a weight from per-100 values', function () {
    $m = Portion::macros(['kcal' => 143, 'protein' => 12.6, 'carbs' => 0.7, 'fat' => 9.5, 'fibre' => null], 110);
    expect($m)->toBe(['kcal' => 157.3, 'protein' => 13.86, 'carbs' => 0.77, 'fat' => 10.45, 'fibre' => null]);
});

it('treats missing macros as zero but keeps fibre null', function () {
    expect(Portion::macros(['kcal' => 100, 'protein' => null, 'carbs' => null, 'fat' => null], 50))
        ->toBe(['kcal' => 50.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0, 'fibre' => null]);
});

it('scales a snapshot to a new weight', function () {
    $s = Portion::scale(['kcal' => 200, 'protein' => 20, 'carbs' => 10, 'fat' => 8, 'fibre' => null], 100, 150);
    expect($s)->toBe(['kcal' => 300.0, 'protein' => 30.0, 'carbs' => 15.0, 'fat' => 12.0, 'fibre' => null]);
    expect(fn () => Portion::scale($s, 0, 10))->toThrow(InvalidArgumentException::class);
});

it('derives quick-add kcal from macros only when kcal is blank', function () {
    expect(Portion::quickKcal(null, 30, 40, 10))->toBe(370.0)->and(Portion::quickKcal(0.0, 30, 40, 10))->toBe(370.0)->and(Portion::quickKcal(500, 30, 40, 10))->toBe(500.0);
});

// ---- Frequent now ----------------------------------------------------------------------------------------------------

it('ranks foods you eat around this hour above ones you eat at other times', function () {
    $logs = [];
    foreach (range(1, 5) as $d) {
        $logs[] = ['food_id' => 1, 'hour' => 7, 'days_ago' => $d];     // breakfast food
        $logs[] = ['food_id' => 2, 'hour' => 19, 'days_ago' => $d];    // dinner food, same count and recency
    }
    expect(Frequent::rank($logs, 7)[0]['food_id'])->toBe(1)->and(Frequent::rank($logs, 19)[0]['food_id'])->toBe(2);
});

it('prefers recent habits over old ones, wraps around midnight, and is stable', function () {
    expect(Frequent::rank([['food_id' => 1, 'hour' => 12, 'days_ago' => 60], ['food_id' => 2, 'hour' => 12, 'days_ago' => 1]], 12)[0]['food_id'])->toBe(2);
    // 23:00 and 01:00 are two hours apart around the clock
    $near = Frequent::rank([['food_id' => 1, 'hour' => 23, 'days_ago' => 1], ['food_id' => 2, 'hour' => 12, 'days_ago' => 1]], 1);
    expect($near[0]['food_id'])->toBe(1);
    $tie = Frequent::rank([['food_id' => 9, 'hour' => 5, 'days_ago' => 1], ['food_id' => 3, 'hour' => 5, 'days_ago' => 1]], 5);
    expect(array_column($tie, 'food_id'))->toBe([3, 9])->and(Frequent::rank([], 5))->toBe([]);
});

it('picks a default meal slot from the hour', function (int $h, string $slot) {
    expect(Frequent::slotForHour($h))->toBe($slot);
})->with([[7, 'breakfast'], [12, 'lunch'], [18, 'dinner'], [22, 'snack'], [2, 'snack']]);
