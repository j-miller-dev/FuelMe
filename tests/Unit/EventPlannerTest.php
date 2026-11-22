<?php

use App\Domain\Progress\EventPlanner as E;

it('returns not achievable safely for a 2 percent a week request', function () {
    // 80 kg to ~78.4 kg in 7 days is 2%/week, double the 1%/week cut cap.
    $r = E::assess(80.0, 78.4, 7, 'intermediate', 2800.0);
    expect($r['verdict'])->toBe('not_achievable_safely')
        ->and($r['requiredPctPerWeek'])->toBe(-2.0)
        ->and(implode(' ', $r['lines']))->toContain('not achievable safely')->toContain('dehydration');
});

it('says on track when the date is reachable at a safe rate', function () {
    $r = E::assess(80.0, 76.0, 12 * 7, 'intermediate', 2800.0);   // ~0.42%/wk
    expect($r['verdict'])->toBe('on_track')->and($r['earliestDays'])->toBeLessThan(12 * 7);
});

it('asks for a change of pace when the realised rate is far from the required one', function () {
    $r = E::assess(80.0, 76.0, 12 * 7, 'intermediate', 2800.0, -0.1);
    expect($r['verdict'])->toBe('needs_rate_change')->and(implode(' ', $r['lines']))->toContain('pick up');
});

it('holds when already at the weight', function () {
    expect(E::assess(80.0, 80.0, 30, 'novice', 2800.0)['verdict'])->toBe('on_track');
});

it('chains phases from each phase target', function () {
    $t = E::timeline(80.0, '2026-11-21', [['until' => '2027-03-01', 'target_kg' => 85.0], ['until' => '2027-05-01', 'target_kg' => 82.0]], 'intermediate', 2800.0);
    expect($t)->toHaveCount(2)->and($t[1]['direction'])->toBe('loss')->and($t[0]['direction'])->toBe('gain');
});
