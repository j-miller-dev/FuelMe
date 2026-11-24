<?php

use App\Domain\Training\SessionCard;

function scSession(string $date, string $title, array $ex, bool $deload = false): array
{
    return ['id' => 1, 'date' => $date, 'title' => $title, 'minutes' => 50, 'deload' => $deload, 'exercises' => $ex];
}
function scEx(string $id, string $title, string $muscle, array $sets): array
{
    return ['id' => $id, 'title' => $title, 'muscle' => $muscle, 'secondary' => [], 'sets' => array_map(fn ($s) => ['type' => $s[2] ?? 'normal', 'kg' => $s[0], 'reps' => $s[1], 'rpe' => $s[3] ?? null], $sets)];
}

it('adds up sets, reps and volume from working sets only, and ignores warm-ups', function () {
    $c = SessionCard::buildAll([scSession('2026-11-10', 'Push', [
        scEx('B', 'Bench Press', 'chest', [[40, 10, 'warmup'], [80, 8], [80, 8], [80, 6]]),
        scEx('F', 'Dips', 'triceps', [[0, 12], [0, 10]]),   // bodyweight: sets and reps, no volume
    ])])[0];

    // 80x8 + 80x8 + 80x6 = 1760 kg; 3 + 2 working sets; 8+8+6+12+10 = 44 reps
    expect($c['volume_kg'])->toBe(1760)->and($c['sets'])->toBe(5)->and($c['reps'])->toBe(44)->and($c['bodyweight_sets'])->toBe(2)->and($c['exercises'])->toBe(2)
        ->and($c['top_set']['exercise'])->toBe('Bench Press')->and($c['top_set']['kg'])->toBe(80.0)
        ->and($c['muscles'][0])->toBe(['muscle' => 'chest', 'sets' => 3, 'pct' => 60]);
});

it('never calls the first time you log a lift a PR, but flags a later session that beats it', function () {
    $cards = SessionCard::buildAll([
        scSession('2026-11-01', 'Push', [scEx('B', 'Bench Press', 'chest', [[80, 8]])]),
        scSession('2026-11-08', 'Push', [scEx('B', 'Bench Press', 'chest', [[80, 8]])]),    // equal: no PR
        scSession('2026-11-15', 'Push', [scEx('B', 'Bench Press', 'chest', [[85, 5]])]),    // heavier and a higher e1RM
    ]);

    expect($cards[0]['pr_count'])->toBe(0)->and($cards[1]['pr_count'])->toBe(0)->and($cards[2]['pr_count'])->toBe(1)
        ->and($cards[2]['rows'][0]['pr'])->toContain('heaviest');
});

it('compares volume with the last session of the same name, not with any session', function () {
    $cards = SessionCard::buildAll([
        scSession('2026-11-01', 'Push', [scEx('B', 'Bench Press', 'chest', [[100, 10]])]),   // 1000
        scSession('2026-11-03', 'Legs', [scEx('S', 'Squat', 'quads', [[100, 10]])]),
        scSession('2026-11-08', 'Push', [scEx('B', 'Bench Press', 'chest', [[100, 11]])]),   // 1100 = +10%
    ]);

    expect($cards[0]['vs_last'])->toBeNull()->and($cards[1]['vs_last'])->toBeNull()->and($cards[2]['vs_last'])->toBe(['pct' => 10, 'last_kg' => 1000]);
});

it('counts failure sets and average RPE only from what was logged', function () {
    $c = SessionCard::buildAll([scSession('2026-11-10', 'Pull', [scEx('R', 'Row', 'back', [[60, 10, 'normal', 8], [60, 9, 'failure']])])])[0];

    expect($c['effort']['failure'])->toBe(1)->and($c['effort']['avg_rpe'])->toBe(8.0);
});

it('groups cards into months newest first with totals', function () {
    $cards = array_reverse(SessionCard::buildAll([
        scSession('2026-10-30', 'Push', [scEx('B', 'Bench Press', 'chest', [[100, 10]])]),
        scSession('2026-11-02', 'Push', [scEx('B', 'Bench Press', 'chest', [[100, 11]])]),
        scSession('2026-11-09', 'Push', [scEx('B', 'Bench Press', 'chest', [[105, 10]])]),
    ]));
    $m = SessionCard::months($cards);

    expect(array_column($m, 'month'))->toBe(['2026-11', '2026-10'])->and($m[0]['sessions'])->toBe(2)->and($m[0]['volume_kg'])->toBe(2150)->and($m[0]['prs'])->toBe(2);   // 100x11 beats 100x10 on estimated 1RM, and 105x10 is heavier
});
