<?php

use App\Domain\Training\Hydration as H;
use App\Domain\Training\PreSessionTimeline as T;

it('uses the NHMRC adequate intake for fluids', function () {
    expect(H::dailyFluidMl('male'))->toBe(2600)->and(H::dailyFluidMl('female'))->toBe(2100);
});

it('sizes pre-session fluid at 5-7 ml/kg and 3-5 ml/kg, rounded to 50 ml', function () {
    expect(H::preMl(80.0, 5, 7))->toBe(['lowMl' => 400, 'highMl' => 550])
        ->and(H::preMl(80.0, 3, 5))->toBe(['lowMl' => 250, 'highMl' => 400]);
});

it('replaces 125-150 percent of the weight lost and never goes negative', function () {
    expect(H::rehydrate(0.8))->toBe(['lowL' => 1.0, 'highL' => 1.2])->and(H::rehydrate(-1.0))->toBe(['lowL' => 0.0, 'highL' => 0.0]);
});

it('says short sessions need no electrolytes and long ones may add sodium', function () {
    expect(H::sodiumNote(40))->toContain('does not need electrolytes')->and(H::sodiumNote(90))->toContain('0.5 to 0.7 g per litre')->toContain('disagree');
});

it('builds a clock-time plan for a midday session', function () {
    $steps = T::build(12 * 60, 40, 80.0, 7 * 60, ['carbsG' => 120, 'proteinG' => 24, 'fatMaxG' => 15, 'fibreMaxG' => 10], ['carbsG' => 25], ['proteinG' => 32, 'carbsG' => 60, 'withinHours' => 2]);
    $byKind = array_column($steps, null, 'kind');
    expect(array_column($steps, 'at'))->toBe(['8:00 am', '9:00 am', '10:00 am', '11:20 am', '12:00 pm', '12:40 pm'])
        ->and($byKind['meal']['text'])->toContain('120 g carbs')->toContain('fat under 15 g')
        ->and($byKind['meal']['inMin'])->toBe(120)
        ->and($byKind['session']['text'])->toContain('does not need electrolytes')
        ->and($byKind['after']['text'])->toContain('32 g protein');
});

it('formats clock times across midnight and noon', function () {
    expect(T::clock(0))->toBe('12:00 am')->and(T::clock(12 * 60))->toBe('12:00 pm')->and(T::clock(-60))->toBe('11:00 pm');
});
