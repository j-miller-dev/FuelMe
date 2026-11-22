<?php

use App\Domain\Training\EffortRamp;
use App\Domain\Training\SessionLoad;
use App\Domain\Training\Strength;

$ramp = ['start' => '2026-11-23', 'weeks' => [[2, 3], [1, 2], [0, 1]]];

it('picks the plan week from the start date and repeats the cycle', function () use ($ramp) {
    expect(EffortRamp::weekFor($ramp, '2026-11-23'))->toMatchArray(['week' => 1, 'of' => 3, 'min' => 2, 'max' => 3, 'label' => '2-3 reps in reserve'])
        ->and(EffortRamp::weekFor($ramp, '2026-11-29')['week'])->toBe(1)       // Sunday still week 1
        ->and(EffortRamp::weekFor($ramp, '2026-11-30')['week'])->toBe(2)
        ->and(EffortRamp::weekFor($ramp, '2026-12-07'))->toMatchArray(['week' => 3, 'label' => 'to failure or 1 rep in reserve'])
        ->and(EffortRamp::weekFor($ramp, '2026-12-14')['week'])->toBe(1);       // cycle repeats
});

it('starts the plan week on the Monday of the start date and is null before it or with no plan', function () use ($ramp) {
    $midweek = ['start' => '2026-11-26'] + $ramp;   // a Thursday
    expect(EffortRamp::weekFor($midweek, '2026-11-23')['week'])->toBe(1)
        ->and(EffortRamp::weekFor($ramp, '2026-11-22'))->toBeNull()
        ->and(EffortRamp::weekFor(null, '2026-11-23'))->toBeNull()
        ->and(EffortRamp::weekFor(['start' => '2026-11-23', 'weeks' => []], '2026-11-23'))->toBeNull();
});

it('words the labels plainly', function () {
    expect(EffortRamp::label(0, 0))->toBe('to failure')
        ->and(EffortRamp::label(1, 1))->toBe('1 rep in reserve')
        ->and(EffortRamp::label(2, 2))->toBe('2 reps in reserve')
        ->and(EffortRamp::label(0, 2))->toBe('to failure or 2 reps in reserve');
});

it('compares hardest sets with the plan, with a half-rep tolerance, and says unknown without effort data', function () {
    expect(EffortRamp::compare([], 2, 3))->toBe(['verdict' => 'unknown', 'avg_rir' => null])
        ->and(EffortRamp::compare([2, 3], 2, 3)['verdict'])->toBe('in_range')
        ->and(EffortRamp::compare([1, 2], 2, 3))->toBe(['verdict' => 'in_range', 'avg_rir' => 1.5])   // 1.5 is within 0.5 of the floor
        ->and(EffortRamp::compare([0, 1], 2, 3)['verdict'])->toBe('harder')
        ->and(EffortRamp::compare([4, 5], 2, 3)['verdict'])->toBe('easier');
});

it('rejects a ramp that is not usable', function () {
    $ok = ['start' => '2026-11-23', 'weeks' => [[2, 3]]];
    expect(EffortRamp::normalise($ok))->toBe(['start' => '2026-11-23', 'weeks' => [[2, 3]]])
        ->and(EffortRamp::normalise(null))->toBeNull()
        ->and(EffortRamp::normalise(['start' => 'tomorrow', 'weeks' => [[2, 3]]]))->toBeNull()
        ->and(EffortRamp::normalise(['start' => '2026-11-23', 'weeks' => []]))->toBeNull()
        ->and(EffortRamp::normalise(['start' => '2026-11-23', 'weeks' => [[3, 2]]]))->toBeNull()
        ->and(EffortRamp::normalise(['start' => '2026-11-23', 'weeks' => [[-1, 2]]]))->toBeNull()
        ->and(EffortRamp::normalise(['start' => '2026-11-23', 'weeks' => [[0, 9]]]))->toBeNull()
        ->and(EffortRamp::normalise(['start' => '2026-11-23', 'weeks' => array_fill(0, 9, [1, 2])]))->toBeNull();
});

it('adds up load from rated sessions only and counts the rest as unrated', function () {
    $s = [
        ['date' => '2026-11-23', 'minutes' => 60, 'rpe' => 7.0],
        ['date' => '2026-11-24', 'minutes' => 40, 'rpe' => null],
        ['date' => '2026-11-25', 'minutes' => 30, 'rpe' => 8.0],
        ['date' => '2026-11-30', 'minutes' => 90, 'rpe' => 9.0],   // outside the range
    ];
    expect(SessionLoad::between($s, '2026-11-23', '2026-11-29'))->toBe(['load' => 660, 'rated' => 2, 'unrated' => 1])
        ->and(SessionLoad::between($s, '2026-12-01', '2026-12-07'))->toBe(['load' => 0, 'rated' => 0, 'unrated' => 0]);
});

it('takes the hardest set per exercise, only where effort was logged, ignoring warm-ups', function () {
    $ex = [
        ['sets' => [['type' => 'warmup', 'weight_kg' => 40, 'reps' => 10, 'rpe' => 10], ['type' => 'normal', 'weight_kg' => 100, 'reps' => 8, 'rpe' => 8], ['type' => 'failure', 'weight_kg' => 100, 'reps' => 9]]],
        ['sets' => [['type' => 'normal', 'weight_kg' => 50, 'reps' => 10]]],   // nothing logged: left out, not guessed
        ['sets' => [['type' => 'normal', 'weight_kg' => 60, 'reps' => 10, 'rpe' => 9]]],
    ];
    expect(Strength::hardestRirs($ex))->toBe([0, 1]);
});
