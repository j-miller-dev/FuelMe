<?php

use App\Domain\Progress\WeeklyReview as W;

function reviewInput(array $o = []): array
{
    return array_merge(['weekStart' => '2026-11-15', 'weekEnd' => '2026-11-21', 'phase' => 'bulk', 'trendKg' => 81.4, 'ratePctPerWeek' => 0.28, 'goalPctPerWeek' => 0.3,
        'daysCounted' => 6, 'daysTotal' => 7, 'daysWithin15' => 4, 'avgProtein' => 168, 'proteinTarget' => 170, 'undereating' => false, 'sessionsDone' => 4, 'sessionsSkipped' => 1,
        'tdee' => 2980, 'tdeeChange' => 20, 'calibrating' => false, 'checkinStatus' => 'ready', 'targetKcal' => 3200, 'proposedKcal' => 3250, 'reasons' => ['Trend is on goal.']], $o);
}

it('writes a short template note from the numbers', function () {
    $r = W::build(reviewInput());
    $note = W::template($r);
    expect($r['pace'])->toBe('on_pace')
        ->and(str_word_count($note))->toBeLessThan(150)
        ->and($note)->toContain('81.4 kg')->toContain('168 g')->toContain('3250')
        ->and(W::groundedNumbers($note, $r))->toBeTrue();
});

it('rejects a note with an invented number', function () {
    $r = W::build(reviewInput());
    expect(W::groundedNumbers('Your trend is 81.4 kg and you should eat 3777 kcal.', $r))->toBeFalse();
});

it('does not push a target change when the check-in is not ready, and flags thin data', function () {
    $r = W::build(reviewInput(['checkinStatus' => 'hold', 'daysCounted' => 3, 'calibrating' => true, 'ratePctPerWeek' => null]));
    $note = W::template($r);
    expect($note)->not->toContain('Consider moving')->toContain('calibrating')->toContain('more days');
});

it('marks a faster-than-goal cut as ahead', function () {
    expect(W::build(reviewInput(['phase' => 'cut', 'goalPctPerWeek' => -0.5, 'ratePctPerWeek' => -0.9]))['pace'])->toBe('ahead');
});
