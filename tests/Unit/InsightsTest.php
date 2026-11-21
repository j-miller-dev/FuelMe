<?php

use App\Domain\Progress\Insights as I;

it('computes Spearman rank correlation, including ties', function () {
    expect(I::spearman([1, 2, 3, 4, 5], [2, 4, 6, 8, 10]))->toBe(1.0)
        ->and(I::spearman([1, 2, 3, 4, 5], [10, 8, 6, 4, 2]))->toBe(-1.0)
        ->and(I::spearman([1, 1, 2, 2], [3, 3, 4, 4]))->toBe(1.0)
        ->and(I::spearman([1, 2, 3], [5, 5, 5]))->toBeNull();
});

it('never shows an insight with fewer than 8 pairs', function () {
    $seven = array_map(fn ($i) => [(float) $i, (float) $i], range(1, 7));
    $eight = array_map(fn ($i) => [(float) $i, (float) $i], range(1, 8));
    expect(I::pair('k', 't', 'sleep', 'energy', $seven))->toBeNull()
        ->and(I::pair('k', 't', 'sleep', 'energy', $eight))->not->toBeNull();
});

it('labels the strength, the sample size and the not-proof caveat, with no causal wording', function () {
    $pairs = array_map(fn ($i) => [(float) $i, (float) $i + ($i % 3)], range(1, 10));
    $r = I::pair('k', 'Sleep and energy', 'sleep quality', 'energy', $pairs);
    expect($r['n'])->toBe(10)->and($r['strength'])->toBeIn(['moderate', 'strong'])
        ->and($r['caveats'][0])->toContain('not proof')->and($r['caveats'])->toHaveCount(2)
        ->and($r['text'])->not->toContain('cause')->not->toContain('because')->toContain('10 days');
});

it('says there is no clear link when the correlation is tiny', function () {
    $x = [1, 2, 3, 4, 5, 6, 7, 8];
    $y = [4, 7, 1, 8, 3, 6, 2, 5];   // rho = -0.095
    expect(I::pair('k', 't', 'a', 'b', array_map(null, $x, $y))['strength'])->toBe('none');
});

it('offers options, not orders, after 3 poor days before a hard session', function () {
    $days = [['date' => '2026-11-21', 'sleep' => 2, 'soreness' => 3, 'energy' => 3], ['date' => '2026-11-20', 'sleep' => 4, 'soreness' => 4, 'energy' => 3], ['date' => '2026-11-19', 'sleep' => 1, 'soreness' => 2, 'energy' => 2]];
    $hook = I::readinessHook($days, ['label' => 'HIT lifting', 'intensity' => 'hit']);
    expect($hook['options'])->toHaveCount(3)->and($hook['message'])->toContain('Your call');
    expect(I::readinessHook($days, null))->toBeNull();
    $days[1]['sleep'] = 4; $days[1]['soreness'] = 2;
    expect(I::readinessHook($days, ['label' => 'x', 'intensity' => 'hit']))->toBeNull();
});

it('needs consecutive days for the hook', function () {
    $gap = [['date' => '2026-11-21', 'sleep' => 1, 'soreness' => 5, 'energy' => 1], ['date' => '2026-11-19', 'sleep' => 1, 'soreness' => 5, 'energy' => 1], ['date' => '2026-11-18', 'sleep' => 1, 'soreness' => 5, 'energy' => 1]];
    expect(I::readinessHook($gap, ['label' => 'x', 'intensity' => 'hit']))->toBeNull();
});
