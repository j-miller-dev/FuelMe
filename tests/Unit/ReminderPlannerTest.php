<?php

use App\Domain\Notify\ReminderPlanner;

function rpTimeline(int $start): array
{
    return [
        ['atMin' => $start - 240, 'kind' => 'fluid', 'title' => 'Start hydrated'],
        ['atMin' => $start - 180, 'kind' => 'meal', 'title' => 'Main pre-session meal'],
        ['atMin' => $start - 40, 'kind' => 'snack', 'title' => 'Optional quick top-up'],
        ['atMin' => $start, 'kind' => 'session', 'title' => 'Session'],
        ['atMin' => $start + 45, 'kind' => 'after', 'title' => 'After'],
    ];
}

it('is due only for the meal, only in the short window after its time', function () {
    $t = rpTimeline(12 * 60 + 15);   // session 12:15, meal 9:15
    $at = 9 * 60 + 15;

    expect(array_column(ReminderPlanner::due($t, $at), 'title'))->toBe(['Main pre-session meal'])
        ->and(ReminderPlanner::due($t, $at + 14))->toHaveCount(1)
        ->and(ReminderPlanner::due($t, $at + 15))->toBe([])       // too late: never tell someone to eat 3 hours late
        ->and(ReminderPlanner::due($t, $at - 1))->toBe([]);
    expect(ReminderPlanner::due($t, 12 * 60))->toBe([]);          // the session itself, fluids and the after note never notify
});

it('stays quiet at night', function () {
    expect(ReminderPlanner::due(rpTimeline(6 * 60 + 30), 3 * 60 + 30))->toBe([])        // an early session whose meal would be at 03:30
        ->and(ReminderPlanner::due(rpTimeline(6 * 60 + 30), 5 * 60 + 59))->toBe([]);
    expect(ReminderPlanner::due(rpTimeline(26 * 60), 23 * 60))->toBe([]);               // a meal after 21:30
    expect(ReminderPlanner::due(rpTimeline(9 * 60), 6 * 60))->toHaveCount(1);           // exactly 06:00 is fine
});

it('builds a stable key and generic wording', function () {
    expect(ReminderPlanner::key('2026-11-23', 'tpl-4', 'meal'))->toBe('session:2026-11-23:tpl-4:meal');
    $t = ReminderPlanner::text('Norwegian 4x4', '12:15');
    expect($t['body'])->toContain('Norwegian 4x4 at 12:15')->and($t['body'])->not->toMatch('/\d+ ?g\b/');   // no grams on a lock screen
});
