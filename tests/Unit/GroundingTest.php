<?php

use App\Domain\Coach\Grounding as G;

$results = [['get_targets' => ['kcal' => 3250, 'protein' => 170, 'trend_kg' => 81.43, 'rate_pct' => 0.28, 'lines' => ['Your target changed 3 days ago.'], 'date' => '2026-11-23']]];

it('accepts numbers that come from the tool results, with rounding', function () use ($results) {
    expect(G::ungrounded('Your target is 3,250 kcal and protein is 170 g. Trend 81.4 kg, moving 0.3% a week.', $results))->toBe([])
        ->and(G::ungrounded('It changed 3 days ago, on the 23rd.', $results))->toBe([]);
});

it('flags a number no tool returned', function () use ($results) {
    expect(G::ungrounded('You should eat 3400 kcal.', $results))->toBe([3400.0])
        ->and(G::ungrounded('About 3,300 kcal.', $results))->toBe([3300.0]);
});

it('flags arithmetic the model did itself', function () use ($results) {
    expect(G::ungrounded('That is 80 kcal lower than before.', $results))->toBe([80.0]);
});

it('allows numbers the person used in their own question', function () use ($results) {
    expect(G::ungrounded('For your 6:30 session, eat early.', $results, 'what should I eat before my 6:30 BJJ'))->toBe([]);
});

it('ignores list markers but not real figures after them', function () use ($results) {
    expect(G::ungrounded("1. Eat 170 g protein.\n2. Sleep.", $results))->toBe([])
        ->and(G::ungrounded("1. Eat 190 g protein.", $results))->toBe([190.0]);
});

it('is fine with text that has no numbers', function () {
    expect(G::ungrounded('You are calibrating, so keep logging.', []))->toBe([]);
});
