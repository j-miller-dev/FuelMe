<?php

use App\Domain\Supplements\CreatinePlan as C;

function creatineDays(string $from, int $n, float $g): array
{
    $o = [];
    for ($i = 0; $i < $n; $i++) {
        $o[date('Y-m-d', strtotime("$from +$i days"))] = $g;
    }

    return $o;
}

it('works out the loading dose from bodyweight and caps it', function () {
    expect(C::loadingTargetG(74.0))->toBe(22)->and(C::loadingTargetG(40.0))->toBe(12)->and(C::loadingTargetG(200.0))->toBe(30)->and(C::loadingTargetG(10.0))->toBe(10);
});

it('loads for the chosen days and then says to continue at the maintenance dose', function () {
    $plan = ['mode' => 'loading', 'start' => '2026-11-01', 'loading_days' => 5, 'maintenance_g' => 5.0];
    $g = creatineDays('2026-11-01', 3, 20.0);
    $s = C::status($plan, $g, 74.0, '2026-11-03');
    expect($s['phase'])->toBe('loading')->and($s['targetG'])->toBe(22.0)->and($s['progress'])->toBe(['done' => 3, 'of' => 5, 'label' => 'loading days'])->and($s['saturated'])->toBeFalse();
    $g = creatineDays('2026-11-01', 5, 20.0);
    $s = C::status($plan, $g, 74.0, '2026-11-05');
    expect($s['phase'])->toBe('saturated')->and($s['saturated'])->toBeTrue()->and($s['continueG'])->toBe(5.0)->and($s['serveG'])->toBe(5.0)->and($s['message'])->toContain('5 g a day');
    $s4 = C::status(['maintenance_g' => 4.0] + $plan, creatineDays('2026-11-01', 5, 20.0), 74.0, '2026-11-05');
    expect($s4['serveG'])->toBe(4.0)->and($s4['targetG'])->toBe(4.0);   // after loading a tap logs the daily dose, not a loading serve
});

it('does not count an unfinished loading day, or a day under 80% of the target', function () {
    $plan = ['mode' => 'loading', 'start' => '2026-11-01', 'loading_days' => 5, 'maintenance_g' => 5.0];
    $g = creatineDays('2026-11-01', 4, 22.0) + ['2026-11-05' => 5.0];   // today only 5 g so far
    expect(C::status($plan, $g, 74.0, '2026-11-05')['progress']['done'])->toBe(4)->and(C::status($plan, $g, 74.0, '2026-11-05')['saturated'])->toBeFalse();
    $g2 = creatineDays('2026-11-01', 5, 10.0);   // 10 g is under 80% of 22
    expect(C::status($plan, $g2, 74.0, '2026-11-05')['progress']['done'])->toBe(0);
});

it('builds up over 28 days without loading, and is saturated only with 26 of 28 and a full window', function () {
    $plan = ['mode' => 'maintenance', 'start' => '2026-10-01', 'loading_days' => 5, 'maintenance_g' => 4.0];
    $g = creatineDays('2026-10-01', 20, 5.0);
    $s = C::status($plan, $g, 74.0, '2026-10-20');
    expect($s['phase'])->toBe('building')->and($s['progress']['done'])->toBe(20)->and($s['targetG'])->toBe(4.0);
    $g = creatineDays('2026-10-01', 28, 5.0);
    $s = C::status($plan, $g, 74.0, '2026-10-28');
    expect($s['phase'])->toBe('saturated')->and($s['continueG'])->toBe(4.0)->and($s['message'])->toContain('Continue at 4 g a day');
    // 3 missed days in the window: 25 of 28 is not enough yet
    unset($g['2026-10-05'], $g['2026-10-10'], $g['2026-10-15']);
    expect(C::status($plan, $g, 74.0, '2026-10-28')['phase'])->toBe('building');
    // 2 missed days: 26 of 28 is enough
    $g = creatineDays('2026-10-01', 28, 5.0); unset($g['2026-10-05'], $g['2026-10-10']);
    expect(C::status($plan, $g, 74.0, '2026-10-28')['phase'])->toBe('saturated');
});

it('falls back to the plain build-up when loading has not finished in two weeks, and says so', function () {
    $plan = ['mode' => 'loading', 'start' => '2026-11-01', 'loading_days' => 5, 'maintenance_g' => 5.0];
    $s = C::status($plan, creatineDays('2026-11-01', 20, 3.0), 74.0, '2026-11-20');
    expect($s['phase'])->toBe('building')->and($s['note'])->toContain('two weeks');
});

it('waits for a plan that starts in the future', function () {
    $s = C::status(['mode' => 'loading', 'start' => '2026-12-01', 'loading_days' => 5, 'maintenance_g' => 5.0], [], 74.0, '2026-11-20');
    expect($s['phase'])->toBe('not_started')->and($s['saturated'])->toBeFalse();
});

it('rejects an unusable plan', function () {
    $ok = ['mode' => 'loading', 'start' => '2026-11-01', 'loading_days' => 6, 'maintenance_g' => 3.5];
    expect(C::normalise($ok))->toBe($ok)
        ->and(C::normalise(null))->toBeNull()
        ->and(C::normalise(['mode' => 'binge'] + $ok))->toBeNull()
        ->and(C::normalise(['loading_days' => 10] + $ok))->toBeNull()
        ->and(C::normalise(['maintenance_g' => 12] + $ok))->toBeNull()
        ->and(C::normalise(['start' => 'soon'] + $ok))->toBeNull();
});
