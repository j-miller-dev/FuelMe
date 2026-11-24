<?php

use App\Domain\Training\PersonalRecords;

function prSession(string $date, array $sets, string $id = 'BP', string $title = 'Bench Press'): array
{
    return ['date' => $date, 'title' => 'Push', 'exercises' => [[
        'id' => $id, 'title' => $title, 'muscle' => 'chest', 'secondary' => [],
        'sets' => array_map(fn ($s) => ['type' => $s[2] ?? 'normal', 'kg' => $s[0], 'reps' => $s[1], 'rpe' => null], $sets),
    ]]];
}

function prFind(array $r, string $id = 'BP'): array
{
    return array_values(array_filter($r, fn ($x) => $x['id'] === $id))[0];
}

it('finds heaviest weight, best e1RM, best reps per weight and best session volume (hand-checked)', function () {
    $r = PersonalRecords::build([
        prSession('2026-10-01', [[60, 10, 'warmup'], [80, 8], [80, 7]]),
        prSession('2026-10-08', [[85, 6], [80, 9]]),
        prSession('2026-10-15', [[80, 8], [75, 12]]),
    ], '2026-11-24');
    $b = prFind($r);
    expect($b['sessions'])->toBe(3)
        ->and($b['heaviest'])->toMatchArray(['kg' => 85.0, 'reps' => 6, 'date' => '2026-10-08'])
        // Epley: 80x9 = 80 * 1.3 = 104.0; 85x6 = 85 * 1.2 = 102.0; 75x12 = 75 * 1.4 = 105.0
        ->and($b['best_e1rm'])->toMatchArray(['e1rm' => 105.0, 'kg' => 75.0, 'reps' => 12, 'date' => '2026-10-15'])
        // volume: s1 80*8+80*7 = 1200 (warm-up out), s2 85*6+80*9 = 1230, s3 80*8+75*12 = 1540
        ->and($b['best_volume'])->toMatchArray(['kg' => 1540.0, 'sets' => 2, 'date' => '2026-10-15']);
    $at80 = array_values(array_filter($b['rep_bests'], fn ($x) => $x['kg'] === 80.0))[0];
    expect($at80)->toMatchArray(['reps' => 9, 'date' => '2026-10-08']);
    // warm-up weight never appears
    expect(array_filter($b['rep_bests'], fn ($x) => $x['kg'] === 60.0))->toBe([]);
});

it('calls a record new only when it beats an earlier session within the window', function () {
    $s = [prSession('2026-11-01', [[80, 8]]), prSession('2026-11-20', [[82.5, 8]])];
    $b = prFind(PersonalRecords::build($s, '2026-11-24'));
    expect($b['heaviest']['new'])->toBeTrue()->and($b['has_new'])->toBeTrue();
    // the same record seen 30 days later is no longer "new"
    expect(prFind(PersonalRecords::build($s, '2026-12-25'))['heaviest']['new'])->toBeFalse();
});

it('never calls the first logged session, or a first time at a weight, a PR', function () {
    $first = prFind(PersonalRecords::build([prSession('2026-11-20', [[80, 8]])], '2026-11-24'));
    expect($first['heaviest']['new'])->toBeFalse()->and($first['best_e1rm']['new'])->toBeFalse()->and($first['has_new'])->toBeFalse();
    // a lighter, never-used weight later is a baseline for that weight, not a PR
    $later = prFind(PersonalRecords::build([prSession('2026-11-01', [[80, 8]]), prSession('2026-11-20', [[60, 15]])], '2026-11-24'));
    $at60 = array_values(array_filter($later['rep_bests'], fn ($x) => $x['kg'] === 60.0))[0];
    expect($at60['new'])->toBeFalse();
});

it('does not count a repeat of the same number as a new record', function () {
    $r = prFind(PersonalRecords::build([prSession('2026-11-01', [[80, 8]]), prSession('2026-11-20', [[80, 8]])], '2026-11-24'));
    expect($r['heaviest']['date'])->toBe('2026-11-01')->and($r['heaviest']['new'])->toBeFalse()->and($r['has_new'])->toBeFalse();
});

it('skips sets without a weight or reps instead of inventing a record', function () {
    $r = PersonalRecords::build([prSession('2026-11-01', [[null, 12], [0, 10], [80, null]], 'PU', 'Push Up')], '2026-11-24');
    expect($r)->toBe([]);
});

it('uses logged effort for the e1RM but never for the heaviest weight', function () {
    $s = prSession('2026-11-01', [[100, 5, 'failure']]);
    $b = prFind(PersonalRecords::build([$s], '2026-11-24'));
    expect($b['heaviest']['kg'])->toBe(100.0)->and($b['best_e1rm']['e1rm'])->toBe(116.7);   // failure = 0 in reserve: 100 * (1 + 5/30)
});

it('lists the most recently trained exercise first and merges repeats of one exercise in a session', function () {
    $a = prSession('2026-11-01', [[80, 8]], 'BP', 'Bench Press');
    $b = prSession('2026-11-10', [[100, 5]], 'SQ', 'Squat');
    $dup = ['date' => '2026-11-12', 'title' => 'x', 'exercises' => [
        ['id' => 'BP', 'title' => 'Bench Press', 'muscle' => 'chest', 'secondary' => [], 'sets' => [['type' => 'normal', 'kg' => 80, 'reps' => 6, 'rpe' => null]]],
        ['id' => 'BP', 'title' => 'Bench Press', 'muscle' => 'chest', 'secondary' => [], 'sets' => [['type' => 'normal', 'kg' => 80, 'reps' => 6, 'rpe' => null]]],
    ]];
    $r = PersonalRecords::build([$a, $b, $dup], '2026-11-24');
    expect(array_column($r, 'id'))->toBe(['BP', 'SQ'])->and($r[0]['best_volume']['sets'])->toBe(2)->and($r[0]['sessions'])->toBe(2);
});
