<?php

use App\Domain\Progress\HitRules;

function dayOf(float $kcal, float $p, float $c, float $f): array
{
    return ['kcal' => $kcal, 'protein' => $p, 'carbs' => $c, 'fat' => $f];
}

const TARGET = ['kcal' => 3000.0, 'protein' => 160.0, 'carbs' => 380.0, 'fat' => 80.0];

it('marks a day on target when every macro is inside its tolerance', function () {
    $r = HitRules::evaluate(dayOf(3100, 165, 390, 78), TARGET);
    expect($r['logged'])->toBeTrue()->and($r['hits'])->toBe(['kcal' => true, 'protein' => true, 'carbs' => true, 'fat' => true]);
});

it('treats energy, carbs and fat as two-sided but protein as a floor only', function () {
    $r = HitRules::evaluate(dayOf(3400, 220, 450, 120), TARGET);
    expect($r['hits'])->toBe(['kcal' => false, 'protein' => true, 'carbs' => false, 'fat' => false]);
    $under = HitRules::evaluate(dayOf(2600, 140, 300, 60), TARGET);
    expect($under['hits'])->toBe(['kcal' => false, 'protein' => false, 'carbs' => false, 'fat' => false]);
});

it('is inclusive right at the tolerance edge', function () {
    expect(HitRules::evaluate(dayOf(3150, 152, 380, 80), TARGET)['hits'])->toMatchArray(['kcal' => true, 'protein' => true]);   // +5% and 95%
    expect(HitRules::evaluate(dayOf(3151, 151, 380, 80), TARGET)['hits'])->toMatchArray(['kcal' => false, 'protein' => false]);
});

it('does not judge a day with under 40% logged (not logged, never a miss)', function () {
    $r = HitRules::evaluate(dayOf(1100, 90, 100, 40), TARGET);
    expect($r)->toBe(['logged' => false, 'hits' => null]);
    expect(HitRules::evaluate(dayOf(1200, 90, 100, 40), TARGET)['logged'])->toBeTrue();   // exactly 40%
});

it('does not judge anything against a zero target', function () {
    expect(HitRules::evaluate(dayOf(500, 10, 10, 10), ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0])['logged'])->toBeFalse();
});

it('uses your own tolerances and clamps silly ones', function () {
    $tight = ['kcal_pct' => 1, 'protein_min_pct' => 100, 'carbs_pct' => 10, 'fat_pct' => 10];
    expect(HitRules::evaluate(dayOf(3100, 165, 380, 80), TARGET, $tight)['hits']['kcal'])->toBeFalse();
    expect(HitRules::normalise(['kcal_pct' => 500, 'protein_min_pct' => 0]))->toMatchArray(['kcal_pct' => 30.0, 'protein_min_pct' => 50.0, 'carbs_pct' => 10.0, 'fat_pct' => 10.0]);
    expect(HitRules::normalise(null))->toBe(HitRules::DEFAULTS);
});

it('summarises with energy and protein only, so a missed side macro does not fail the day', function () {
    $days = [
        HitRules::evaluate(dayOf(3000, 160, 300, 80), TARGET),   // carbs miss only: still counts
        HitRules::evaluate(dayOf(3000, 120, 380, 80), TARGET),   // protein miss
        HitRules::evaluate(dayOf(500, 20, 50, 10), TARGET),      // not logged
        HitRules::evaluate(dayOf(2950, 170, 380, 80), TARGET),
        HitRules::evaluate(dayOf(3050, 161, 370, 82), TARGET),
    ];
    expect(HitRules::summary($days))->toBe(['logged' => 4, 'onTarget' => 3, 'total' => 5]);
});
