<?php

use App\Domain\Nutrition\TargetCalculator;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\NudgePicker;
use App\Domain\Progress\TrendStats;
use App\Domain\Progress\UndereatingDetector;
use App\Domain\Progress\WeeklyCheckin;

function trendSeries(int $days, float $start, float $kgPerWeek): array
{
    return array_map(fn ($i) => ['date' => date('Y-m-d', strtotime("2026-01-01 +{$i} days")), 'trend' => $start + $kgPerWeek / 7 * $i], range(0, $days - 1));
}

it('measures the trend slope in kg and % per week, and refuses with too little data', function () {
    $s = TrendStats::slope(trendSeries(30, 74.0, 0.25));

    expect($s['kgPerWeek'])->toBe(0.25)->and($s['pctPerWeek'])->toBe(round(0.25 / (74.0 + 0.25 / 7 * 29) * 100, 3))->and($s['days'])->toBe(14)->and($s['deltaKg'])->toBe(0.5);
    expect(TrendStats::slope(trendSeries(6, 74.0, 0.25)))->toBeNull();
    // the PREVIOUS 14 days: ends 14 days earlier
    expect(TrendStats::slope(trendSeries(30, 74.0, 0.25), 14, 10, 14)['kgPerWeek'])->toBe(0.25);
});

it('counts a day as logged only when it holds a real share of the target', function () {
    $d = Adherence::loggedOnly([['date' => 'a', 'kcal' => 3000.0, 'target' => 3000.0], ['date' => 'b', 'kcal' => 900.0, 'target' => 3000.0], ['date' => 'c', 'kcal' => 0.0, 'target' => 3000.0]]);

    expect(array_column($d, 'kcal'))->toBe([3000.0, null, null])->and(Adherence::loggedCount($d))->toBe(1);
});

function day(?float $kcal, float $target = 3000.0, float $protein = 150.0): array
{
    return ['date' => 'd', 'kcal' => $kcal, 'target' => $target, 'protein' => $protein, 'proteinTarget' => 165.0];
}

it('flags undereating at 4 or more of the last 7 logged days under 90%, ignoring unlogged days', function () {
    $low = fn () => day(2500.0);   // 83%
    $ok = fn () => day(2900.0);

    $r = UndereatingDetector::detect([$ok(), $ok(), $ok(), $low(), $low(), $low(), $low()]);
    expect($r['flag'])->toBeTrue()->and($r['lowDays'])->toBe(4)->and($r['loggedDays'])->toBe(7)->and($r['gapKcal'])->toBe(round((4 * 500 + 3 * 100) / 7));

    expect(UndereatingDetector::detect([$ok(), $ok(), $ok(), $ok(), $low(), $low(), $low()])['flag'])->toBeFalse();
    // forgetting to log is NOT undereating: unlogged days are skipped, not counted as zero
    expect(UndereatingDetector::detect([day(null), day(null), day(null), day(null), $ok(), $ok(), $ok()])['flag'])->toBeFalse();
    // only the last 7 logged days count
    expect(UndereatingDetector::detect([$low(), $low(), $low(), $low(), $ok(), $ok(), $ok(), $ok(), $ok(), $ok(), $ok()])['flag'])->toBeFalse();
});

it('picks usual foods that close the gap, protein first when protein is short, never duplicates or tiny items', function () {
    $foods = [
        ['name' => 'Peanut butter, smooth', 'grams' => 30.0, 'unit' => 'g', 'kcal' => 190.0, 'protein' => 7.0],
        ['name' => 'Peanut butter, crunchy', 'grams' => 30.0, 'unit' => 'g', 'kcal' => 188.0, 'protein' => 7.0],
        ['name' => 'Greek yoghurt', 'grams' => 200.0, 'unit' => 'g', 'kcal' => 190.0, 'protein' => 20.0],
        ['name' => 'Lettuce', 'grams' => 50.0, 'unit' => 'g', 'kcal' => 8.0, 'protein' => 0.5],
        ['name' => 'Milk', 'grams' => 300.0, 'unit' => 'mL', 'kcal' => 200.0, 'protein' => 10.0],
        ['name' => 'Banana', 'grams' => 120.0, 'unit' => 'g', 'kcal' => 110.0, 'protein' => 1.3],
    ];

    $p = NudgePicker::pick($foods, 400.0, 40.0);
    $names = array_column($p, 'name');

    expect($names[0])->toBe('Greek yoghurt')->and($names)->not->toContain('Lettuce')->and(count(array_filter($names, fn ($n) => str_starts_with($n, 'Peanut butter'))))->toBe(1)->and(count($p))->toBe(3);
    expect(NudgePicker::pick($foods, 0.0, 0.0))->toBe([]);
});

function checkin(array $o = []): array
{
    $slope = ['kgPerWeek' => 0.1, 'pctPerWeek' => 0.14, 'days' => 14, 'deltaKg' => 0.2];

    return WeeklyCheckin::evaluate($o + ['trendKg' => 74.0, 'tdee' => 3000.0, 'tdeeSd' => 120.0, 'phase' => 'bulk', 'goalPctPerWeek' => 0.25, 'previousTarget' => 3000.0,
        'tdeeAtLastTarget' => 2900.0, 'slope14' => $slope, 'slopePrev14' => $slope, 'weighIns14' => 8, 'loggedDays7' => 7, 'daysOfData' => 30, 'daysSinceChange' => 7]);
}

it('holds the target and says what to fix when data is thin', function (array $o, string $reason) {
    $c = checkin($o);

    expect($c['status'])->toBe('hold')->and($c['proposedKcal'])->toBe(3000)->and($c['change'])->toBe(0)->and(implode(' ', $c['holdReasons']))->toContain($reason);
})->with([
    'few weigh-ins' => [['weighIns14' => 2], '2 times'],
    'few logged days' => [['loggedDays7' => 3], '3 of the last 7'],
    'too early' => [['daysOfData' => 9], '9 days of data'],
]);

it('waits until a week has passed since the last change', function () {
    $c = checkin(['daysSinceChange' => 3]);

    expect($c['status'])->toBe('wait')->and($c['change'])->toBe(0)->and($c['lines'][0])->toContain('4 days');
});

it('moves 60% of the way to the ideal and no more than 150 kcal a week, exactly as the engine does', function () {
    $c = checkin();
    $ideal = (int) TargetCalculator::weeklyTarget(3000.0, 74.0, 0.25, null);   // 3204
    $expected = (int) TargetCalculator::weeklyTarget(3000.0, 74.0, 0.25, 3000.0);

    expect($c['status'])->toBe('ready')->and($c['idealKcal'])->toBe($ideal)->and($c['proposedKcal'])->toBe($expected)->and($c['change'])->toBe($expected - 3000)->and($c['change'])->toBeGreaterThan(0);

    // a huge gap is capped at 150
    $big = checkin(['tdee' => 3800.0]);
    expect($big['change'])->toBe(150);
});

it('explains every change in plain lines: expenditure moved, trend moved, smoothing', function () {
    $lines = implode("\n", checkin()['lines']);

    expect($lines)->toContain('Estimated expenditure is 3000 kcal/day')->and($lines)->toContain('up 100 kcal since your last target')
        ->and($lines)->toContain('+0.14% a week')->and($lines)->toContain('Your goal is +0.25% a week')->and($lines)->toContain('move 60% of the way');
});

it('applies the bulk guards only for a sustained miss with enough logging', function () {
    $fast = ['kgPerWeek' => 0.6, 'pctPerWeek' => 0.8, 'days' => 14, 'deltaKg' => 1.2];
    $slow = ['kgPerWeek' => 0.02, 'pctPerWeek' => 0.03, 'days' => 14, 'deltaKg' => 0.04];

    $c = checkin(['tdee' => 3300.0, 'slope14' => $fast, 'slopePrev14' => $fast]);
    expect($c['guard'])->toBe('bulk_too_fast')->and($c['proposedKcal'])->toBeLessThan(3000);

    // one fast fortnight is not enough
    expect(checkin(['slope14' => $fast, 'slopePrev14' => ['pctPerWeek' => 0.2] + $fast])['guard'])->toBeNull();

    $c = checkin(['slope14' => $slow, 'slopePrev14' => $slow, 'tdee' => 2900.0]);
    expect($c['guard'])->toBe('bulk_too_slow')->and($c['proposedKcal'])->toBeGreaterThan(3000)->and($c['proposedKcal'])->toBeLessThanOrEqual(3150);
    // good adherence is required before it adds food
    expect(checkin(['slope14' => $slow, 'slopePrev14' => $slow, 'tdee' => 2900.0, 'loggedDays7' => 5])['guard'])->toBeNull();
});

it('never goes below the floor and notes when the estimate is still settling', function () {
    $c = checkin(['tdee' => 1500.0, 'previousTarget' => 1450.0, 'phase' => 'cut', 'goalPctPerWeek' => -1.0, 'tdeeSd' => 200.0]);

    expect($c['proposedKcal'])->toBeGreaterThanOrEqual(1400)->and($c['calibrating'])->toBeTrue()->and(implode(' ', $c['lines']))->toContain('still settling');
});

it('does not raise a target the person is not reaching, and says why', function () {
    $slow = ['kgPerWeek' => 0.02, 'pctPerWeek' => 0.03, 'days' => 14, 'deltaKg' => 0.04];
    $c = checkin(['slope14' => $slow, 'slopePrev14' => $slow, 'tdee' => 3300.0, 'undereating' => true]);

    expect($c['change'])->toBe(0)->and($c['proposedKcal'])->toBe(3000)->and($c['guard'])->toBeNull()->and(implode(' ', $c['lines']))->toContain('eating under your current target');
    // lowering is still allowed while undereating (weight is rising faster than goal)
    $fast = ['kgPerWeek' => 0.6, 'pctPerWeek' => 0.8, 'days' => 14, 'deltaKg' => 1.2];
    expect(checkin(['tdee' => 3300.0, 'slope14' => $fast, 'slopePrev14' => $fast, 'undereating' => true])['change'])->toBeLessThan(0);
});
