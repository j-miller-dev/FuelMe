<?php

use App\Domain\Nutrition\ExerciseCalculator as X;
use App\Domain\Nutrition\MacroPlanner as M;
use App\Domain\Nutrition\PeriWorkout as P;

// Ported from BLUEPRINT Appendix C (tests/Engine/macro_tests.php stays as the dependency-free runner).

function referenceWeek(): array
{
    return [
        'mon' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60], ['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50]],
        'tue' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]],
        'wed' => [['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50]],
        'thu' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]],
        'fri' => [['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50], ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40]],
        'sat' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 90]],
        'sun' => [],
    ];
}

function referencePlan(array $over = []): array
{
    return M::plan($over + ['weightKg' => 74.0, 'bodyFatPct' => 14.0, 'phase' => 'bulk', 'kcalTarget' => 3150, 'week' => referenceWeek(), 'proteinGPerKg' => 2.2]);
}

it('computes BJJ net energy as (MET - 1) x kg x hours', function () {
    $r = X::session('bjj', 'standard', 60, 74);
    expect((int) $r['netKcal'])->toBe(481)
        ->and($r['low'])->toBeLessThan($r['netKcal'])
        ->and($r['high'])->toBeGreaterThan($r['netKcal']);
});

it('conserves weekly calories within 30 kcal', function () {
    $sum = array_sum(array_column(referencePlan()['days'], 'kcal'));
    expect(abs($sum - 7 * 3150))->toBeLessThanOrEqual(30);
});

it('honours the protein override and the fat floor', function () {
    $w = referencePlan()['weekly'];
    expect($w['proteinGPerKg'])->toEqualWithDelta(2.2, 0.02)
        ->and($w['fatGPerKg'])->toBeGreaterThanOrEqual(0.6);
});

it('periodises carbs by training load without negatives', function () {
    $p = referencePlan();
    expect($p['days']['sat']['carbs'])->toBeGreaterThan($p['days']['sun']['carbs'])
        ->and(min(array_column($p['days'], 'carbs')))->toBeGreaterThanOrEqual(0)
        ->and(min(array_column($p['days'], 'carbGPerKg')))->toBeGreaterThanOrEqual(3.0)
        ->and(max(array_column($p['days'], 'carbGPerKg')))->toBeLessThanOrEqual(12.0);
});

it('raises an unsafe cut to the energy-availability floor', function () {
    $cut = M::plan(['weightKg' => 74.0, 'bodyFatPct' => 12.0, 'phase' => 'cut', 'kcalTarget' => 1700, 'week' => referenceWeek()]);
    expect(array_column($cut['warnings'], 'code'))->toContain('energy_floor')
        ->and($cut['weekly']['kcalTargetUsed'])->toBeGreaterThan(1700);
});

it('flags the training conflict in keto mode', function () {
    $keto = referencePlan(['carbMode' => 'keto']);
    expect($keto['days']['mon']['carbs'])->toEqual(30)
        ->and(array_column($keto['warnings'], 'code'))->toContain('keto_training');
});

it('sizes peri-workout carbs and protein inside the evidence ranges', function () {
    $pw = P::plan(74.0, ['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60], 2.0, 5.0, ['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 50]);
    expect($pw['pre']['carbsG'])->toBeBetween(74, 296)
        ->and($pw['post']['proteinG'])->toBeBetween(20, 50);

    $double = P::plan(74.0, ['type' => 'bjj', 'intensity' => 'hard', 'minutes' => 60], 2.0, 6.0, ['type' => 'hiit', 'intensity' => 'moderate', 'minutes' => 30]);
    expect($double['post']['carbsGPerHour'])->toEqual([74, 89]);
});

it('keeps the planner invariants over random weights, phases and weeks', function (int $seed) {
    mt_srand($seed);
    $types = [['bjj', 'standard'], ['bjj', 'hard'], ['lifting', 'hit'], ['lifting', 'heavy'], ['hiit', 'moderate'], ['norwegian_4x4', 'standard']];
    $week = [];
    foreach (M::DAYS as $d) {
        $week[$d] = [];
        for ($i = mt_rand(0, 2); $i > 0; $i--) {
            [$t, $int] = $types[mt_rand(0, count($types) - 1)];
            $week[$d][] = ['type' => $t, 'intensity' => $int, 'minutes' => mt_rand(20, 120)];
        }
    }
    $kg = mt_rand(500, 1200) / 10;
    $phase = ['cut', 'maintain', 'bulk'][mt_rand(0, 2)];
    $plan = M::plan([
        'weightKg' => $kg, 'bodyFatPct' => mt_rand(80, 300) / 10, 'phase' => $phase,
        'kcalTarget' => $kg * mt_rand(300, 450) / 10, 'week' => $week,
    ]);

    $codes = array_column($plan['warnings'], 'code');
    foreach ($plan['days'] as $day) {
        foreach (['kcal', 'protein', 'fat', 'carbs'] as $k) {
            expect(is_nan((float) $day[$k]))->toBeFalse()
                ->and($day[$k])->toBeGreaterThanOrEqual(0);
        }
    }
    expect($plan['weekly']['fatGPerKg'])->toBeGreaterThanOrEqual(0.55); // 0.6 g/kg floor, minus rounding
    if (! in_array('infeasible', $codes, true)) {
        $sum = array_sum(array_column($plan['days'], 'kcal'));
        expect(abs($sum - 7 * $plan['weekly']['kcalTargetUsed']))->toBeLessThanOrEqual(40);
    }
})->with(range(1, 150));

it('says so when a protein request is capped at 35% of calories', function () {
    $plan = referencePlan(['kcalTarget' => 2450, 'proteinGPerKg' => 3.0]);   // 222 g asked, cap 214 g (35% of 2450 / 4); the safe floor for this week is 2400
    expect($plan['days']['mon']['protein'])->toBe(214.0)
        ->and(collect($plan['warnings'])->pluck('code'))->toContain('protein_capped');
    expect(collect(referencePlan()['warnings'])->pluck('code'))->not->toContain('protein_capped');
});

it('raises the fat floor when asked but never lowers it', function () {
    $base = referencePlan()['weekly']['fat'];
    expect(referencePlan(['fatMinGPerKg' => 1.4])['weekly']['fat'])->toBeGreaterThan($base)
        ->and(referencePlan(['fatMinGPerKg' => 0.3])['weekly']['fat'])->toBe($base);
});
