<?php

use App\Domain\Nutrition\MacroPlanner;
use App\Domain\Nutrition\Replanner;
use App\Domain\Training\DayFuel;
use App\Domain\Training\MealComposer;

function trainWeek(array $override = []): array
{
    $bjj = ['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60];
    $lift = ['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40];
    $nor = ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40];

    return $override + ['mon' => [$bjj, $lift], 'tue' => [$bjj], 'wed' => [$lift], 'thu' => [$bjj], 'fri' => [$lift, $nor], 'sat' => [$bjj + ['minutes' => 90]], 'sun' => []];
}

function trainPlan(array $week): array
{
    return MacroPlanner::plan(['weightKg' => 74.0, 'sex' => 'male', 'bodyFatPct' => 14.0, 'phase' => 'bulk', 'kcalTarget' => 3150.0, 'week' => $week, 'proteinGPerKg' => 2.2]);
}

function trainCarbs(array $days): float
{
    return array_sum(array_map(fn ($d) => $d['carbs'], $days));
}

it('keeps the weekly carb total when a session is added today, and leaves past days alone', function () {
    $base = trainPlan(trainWeek());
    $extra = ['type' => 'norwegian_4x4', 'intensity' => 'standard', 'minutes' => 40];
    $eff = trainPlan(trainWeek(['wed' => trainWeek()['wed'] + [1 => $extra]]));   // add a 4x4 on Wednesday

    $r = Replanner::remaining($base['days'], $eff['days'], ['mon', 'tue'], 74.0, $base['weekly']['ffmKg']);

    expect($r['days']['mon'])->toBe($base['days']['mon'])->and($r['days']['tue'])->toBe($base['days']['tue'])
        ->and($r['days']['wed']['carbs'])->toBeGreaterThan($base['days']['wed']['carbs'])
        ->and(abs(trainCarbs($r['days']) - trainCarbs($base['days'])))->toBeLessThanOrEqual(4);   // rounding only
    // protein and fat never move
    foreach (MacroPlanner::DAYS as $k) {
        expect($r['days'][$k]['protein'])->toBe($base['days'][$k]['protein'])->and($r['days'][$k]['fat'])->toBe($base['days'][$k]['fat']);
    }
    // so the extra carbs came out of the days still to come
    $later = ['thu', 'fri', 'sat', 'sun'];
    expect(array_sum(array_map(fn ($k) => $r['days'][$k]['carbs'], $later)))->toBeLessThan(array_sum(array_map(fn ($k) => $base['days'][$k]['carbs'], $later)));
});

it('lowers today when a planned session is cancelled, and keeps every remaining day inside its class range', function () {
    $base = trainPlan(trainWeek());
    $eff = trainPlan(trainWeek(['tue' => []]));   // Tuesday BJJ cancelled

    $r = Replanner::remaining($base['days'], $eff['days'], ['mon'], 74.0, $base['weekly']['ffmKg']);

    expect($r['days']['tue']['carbs'])->toBeLessThan($base['days']['tue']['carbs'])->and(abs(trainCarbs($r['days']) - trainCarbs($base['days'])))->toBeLessThanOrEqual(4);
    foreach (['tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $k) {
        [$lo, $hi] = MacroPlanner::CARB_RANGE[$r['days'][$k]['class']];
        expect($r['days'][$k]['carbs'] / 74.0)->toBeGreaterThanOrEqual($lo - 0.05)->and($r['days'][$k]['carbs'] / 74.0)->toBeLessThanOrEqual($hi + 0.05);
    }
});

it('changes nothing when the effective week is the template week', function () {
    $base = trainPlan(trainWeek());

    $r = Replanner::remaining($base['days'], $base['days'], ['mon', 'tue', 'wed'], 74.0, $base['weekly']['ffmKg']);

    foreach (MacroPlanner::DAYS as $k) {
        expect(abs($r['days'][$k]['carbs'] - $base['days'][$k]['carbs']))->toBeLessThanOrEqual(1);
    }
});

it('puts whatever is left on the last day of the week', function () {
    $base = trainPlan(trainWeek());
    $r = Replanner::remaining($base['days'], $base['days'], ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 74.0, 63.6);

    expect(abs(trainCarbs($r['days']) - trainCarbs($base['days'])))->toBeLessThanOrEqual(4)->and(abs($r['days']['sun']['carbs'] - $base['days']['sun']['carbs']))->toBeLessThanOrEqual(2);
});

// ---- the double-day case ------------------------------------------------------------------------------------------------------

function trainSess(string $key, string $type, string $intensity, int $min, ?string $start): array
{
    return ['key' => $key, 'type' => $type, 'intensity' => $intensity, 'minutes' => $min, 'start' => $start];
}

it('plans BJJ then lifting as the blueprint example: next session resistance-only so about 1 g/kg carbs after', function () {
    $d = DayFuel::plan(74.0, [trainSess('a', 'bjj', 'standard', 60, '06:30'), trainSess('b', 'lifting', 'hit', 40, '12:00')], 2.0);

    expect($d)->toHaveCount(2)->and($d[0]['pre']['carbsG'])->toBe(148)->and($d[0]['pre']['proteinG'])->toBe(22)->and($d[0]['pre']['fatMaxG'])->toBe(8)
        ->and($d[0]['gapToNextHours'])->toBe(4.5)->and($d[0]['post'])->toMatchArray(['proteinG' => 30, 'carbsG' => 74])->and($d[0]['sharedMealNote'])->toBeNull()->and($d[1]['sharedMealNote'])->toBeNull();
});

it('plans a glycolytic double day: 1.0-1.2 g/kg/h for 4 hours after the first', function () {
    $d = DayFuel::plan(74.0, [trainSess('a', 'bjj', 'standard', 60, '06:30'), trainSess('b', 'norwegian_4x4', 'standard', 40, '11:30')]);

    expect($d[0]['post']['carbsGPerHour'])->toBe([74.0, 89.0])->and($d[0]['post']['forHours'])->toBe(4)->and($d[0]['gapToNextHours'])->toBe(4.0);
});

it('says when one meal has to do double duty, and orders sessions by time', function () {
    $d = DayFuel::plan(74.0, [trainSess('b', 'lifting', 'hit', 40, '09:00'), trainSess('a', 'bjj', 'standard', 60, '06:30')]);

    expect(array_column($d, 'key'))->toBe(['a', 'b'])->and($d[0]['gapToNextHours'])->toBe(1.5)->and($d[1]['sharedMealNote'])->toContain('also your meal before');
});

it('copes with a session that has no time set', function () {
    $d = DayFuel::plan(74.0, [trainSess('a', 'bjj', 'standard', 60, null)]);

    expect($d[0]['gapToNextHours'])->toBeNull()->and($d[0]['pre']['carbsG'])->toBeGreaterThan(0);
});

// ---- the meal composer --------------------------------------------------------------------------------------------------------

function fuelCand(string $name, float $carbs, float $protein, float $fat, float $fibre, array $o = []): array
{
    static $n = 0;

    return $o + ['id' => 'f' . ++$n, 'kind' => 'food', 'ref' => $n, 'name' => $name, 'unit' => 'g', 'amount' => 100.0, 'kcal' => $carbs * 4 + $protein * 4 + $fat * 9, 'protein' => $protein, 'carbs' => $carbs, 'fat' => $fat, 'fibre' => $fibre, 'tagged' => false];
}

it('finds options within 10% of the carb target that respect the fat and fibre caps', function () {
    $c = [fuelCand('Banana', 25, 1.3, 0.3, 2.6), fuelCand('White rice, cooked', 56, 5, 0.5, 0.6), fuelCand('Up&Go', 30, 6, 1.5, 1.0), fuelCand('Peanut butter', 6, 7, 15, 2.5), fuelCand('Oats', 60, 11, 6, 8.0)];
    $t = ['carbsG' => 100.0, 'proteinG' => 22.0, 'fatMaxG' => 8.0, 'fibreMaxG' => 5.0];

    $r = MealComposer::compose($t, $c, ['kcal' => 2000, 'protein' => 120, 'carbs' => 300, 'fat' => 60]);

    expect($r['options'])->not->toBeEmpty()->and(count($r['options']))->toBeLessThanOrEqual(3)->and($r['shortfall'])->toBeNull()->and($r['shift'])->toBeNull();
    foreach ($r['options'] as $o) {
        expect(abs($o['carbs'] - 100))->toBeLessThanOrEqual(10)->and($o['fat'])->toBeLessThanOrEqual(8.8)->and($o['fibre'])->toBeLessThanOrEqual(5.5);
        expect(array_column($o['items'], 'name'))->not->toContain('Peanut butter');   // fat is capped, so it never fits
    }
    // what it does to the rest of the day
    expect(abs($r['options'][0]['after']['carbs'] + $r['options'][0]['carbs'] - 300))->toBeLessThanOrEqual(1);
});

it('explains the shortfall instead of pretending when nothing fits', function () {
    $r = MealComposer::compose(['carbsG' => 400.0, 'proteinG' => 30.0, 'fatMaxG' => 8.0, 'fibreMaxG' => 5.0], [fuelCand('Banana', 25, 1.3, 0.3, 2.6)], ['kcal' => 2000, 'protein' => 120, 'carbs' => 500, 'fat' => 60]);

    expect($r['options'])->toBe([])->and($r['shortfall'])->toContain('short')->and($r['shortfall'])->toContain('Banana');
    $none = MealComposer::compose(['carbsG' => 100.0, 'proteinG' => 22.0, 'fatMaxG' => 3.0, 'fibreMaxG' => 3.0], [fuelCand('Oats', 60, 11, 6, 8.0)], ['kcal' => 2000, 'protein' => 120, 'carbs' => 500, 'fat' => 60]);
    expect($none['options'])->toBe([])->and($none['shortfall'])->toContain('low enough in fat and fibre');
});

it('says so when the day does not have the carbs left for the meal', function () {
    $r = MealComposer::compose(['carbsG' => 100.0, 'proteinG' => 22.0, 'fatMaxG' => 8.0, 'fibreMaxG' => 5.0], [fuelCand('White rice, cooked', 56, 5, 0.5, 0.6), fuelCand('Banana', 25, 1.3, 0.3, 2.6)], ['kcal' => 600, 'protein' => 40, 'carbs' => 60, 'fat' => 20]);

    expect($r['shift']['carbsG'])->toBe(40.0)->and($r['shift']['note'])->toContain('Shift about 40 g');
});

it('prefers meals tagged for the window, and offers a food only once per option', function () {
    $c = [fuelCand('White rice, cooked', 50, 4, 0.5, 0.6), fuelCand('Chicken rice bowl', 50, 30, 6, 3.0, ['kind' => 'meal', 'tagged' => true, 'unit' => 'portion', 'amount' => 1.0])];
    $r = MealComposer::compose(['carbsG' => 100.0, 'proteinG' => 34.0, 'fatMaxG' => 12.0, 'fibreMaxG' => 6.0], $c, ['kcal' => 2000, 'protein' => 120, 'carbs' => 300, 'fat' => 60]);

    foreach ($r['options'] as $o) {
        $names = array_column($o['items'], 'name');
        expect($names)->toBe(array_values(array_unique($names)));
    }
    expect($r['options'][0]['items'][0]['kind'])->toBe('meal');
});
