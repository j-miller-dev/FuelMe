<?php

use App\Domain\Progress\Achievements;
use App\Domain\Training\SessionCard;

function acSess(string $date, string $title, array $sets, string $ex = 'B', string $name = 'Bench Press'): array
{
    return ['id' => 1, 'date' => $date, 'title' => $title, 'minutes' => 40, 'deload' => false, 'exercises' => [['id' => $ex, 'title' => $name, 'muscle' => 'chest', 'secondary' => [],
        'sets' => array_map(fn ($s) => ['type' => 'normal', 'kg' => $s[0], 'reps' => $s[1], 'rpe' => null], $sets)]]];
}
function acCards(array $sessions): array
{
    return array_reverse(SessionCard::buildAll($sessions));   // newest first, as the service gives them
}

it('shows nothing on a quiet day, and never a first-ever log as a PR', function () {
    $c = acCards([acSess('2026-11-24', 'Push', [[80, 8]])]);

    expect(Achievements::build(['today' => '2026-11-24', 'cards' => $c, 'week' => null]))->toBe([]);
});

it('celebrates a personal best from today or yesterday only', function () {
    $s = [acSess('2026-11-10', 'Push', [[80, 8]]), acSess('2026-11-23', 'Push', [[85, 6]])];
    $a = Achievements::build(['today' => '2026-11-24', 'cards' => acCards($s), 'week' => null]);

    expect($a)->toHaveCount(1)->and($a[0]['key'])->toBe('pr:2026-11-23:B')->and($a[0]['title'])->toBe('Personal best: Bench Press')->and($a[0]['detail'])->toContain('Heaviest yet: 85 kg × 6');
    expect(Achievements::build(['today' => '2026-11-26', 'cards' => acCards($s), 'week' => null]))->toBe([]);   // two days later it has expired
});

it('flags a volume best only after three earlier sessions of that name', function () {
    $early = [acSess('2026-10-01', 'Push', [[80, 8]]), acSess('2026-10-08', 'Push', [[80, 8]])];
    $two = Achievements::build(['today' => '2026-11-24', 'cards' => acCards([...$early, acSess('2026-11-24', 'Push', [[80, 8], [80, 8], [80, 8]])]), 'week' => null]);
    expect(array_column($two, 'kind'))->not->toContain('volume');

    $three = Achievements::build(['today' => '2026-11-24', 'cards' => acCards([...$early, acSess('2026-10-15', 'Push', [[80, 8]]), acSess('2026-11-24', 'Push', [[80, 8], [80, 8], [80, 8]])]), 'week' => null]);
    $vol = array_values(array_filter($three, fn ($i) => $i['kind'] === 'volume'));
    expect($vol)->toHaveCount(1)->and($vol[0]['detail'])->toContain('1,920 kg')->and($vol[0]['detail'])->toContain('200%');   // 1920 vs 640
});

it('marks lifetime milestones the day they are crossed', function () {
    $a = Achievements::build(['today' => '2026-11-24', 'cards' => acCards([acSess('2026-11-01', 'Push', [[100, 40]]), acSess('2026-11-24', 'Push', [[100, 15]])]), 'week' => null]);
    // 4000 kg then +1500 kg = 5.5 t, crossing 5 t on the second session
    expect(array_column($a, 'key'))->toContain('tonnes:5');
});

it('shows last week\'s wins only Mon-Wed, only when they happened, and calls the first full week the first', function () {
    $week = ['start' => '2026-11-16', 'end' => '2026-11-22', 'planned' => 4, 'done' => 4, 'firstFull' => true, 'loggedDays' => 6, 'proteinDays' => 5, 'sleepNights' => 6, 'sleepOn' => 5];
    $a = Achievements::build(['today' => '2026-11-23', 'cards' => [], 'week' => $week]);

    expect(array_column($a, 'title'))->toBe(['First full training week', 'Protein on target 5 of 7 days', 'Near your sleep target 5 of 6 nights', 'Logged 6 of 7 days']);
    expect(Achievements::build(['today' => '2026-11-26', 'cards' => [], 'week' => $week]))->toBe([]);   // Thursday: gone
    expect(Achievements::build(['today' => '2026-11-23', 'cards' => [], 'week' => ['planned' => 4, 'done' => 3, 'loggedDays' => 3, 'proteinDays' => 2, 'sleepNights' => 3, 'sleepOn' => 1] + $week]))->toBe([]);
});

it('leaves out what you already swiped away', function () {
    $s = [acSess('2026-11-10', 'Push', [[80, 8]]), acSess('2026-11-23', 'Push', [[85, 6]])];

    expect(Achievements::build(['today' => '2026-11-24', 'cards' => acCards($s), 'week' => null, 'dismissed' => ['pr:2026-11-23:B']]))->toBe([]);
});
