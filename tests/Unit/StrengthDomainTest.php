<?php

use App\Domain\Training\HevyMapper;
use App\Domain\Training\Strength;

function sx(string $date, string $id, array $sets, string $muscle = 'quadriceps', array $secondary = []): array
{
    return ['date' => $date, 'title' => 'S', 'exercises' => [['id' => $id, 'title' => $id, 'muscle' => $muscle, 'secondary' => $secondary, 'sets' => array_map(fn ($s) => ['type' => $s[2] ?? 'normal', 'kg' => $s[0], 'reps' => $s[1], 'rpe' => null], $sets)]]];
}

it('estimates 1RM with Epley for 1-12 reps only', function () {
    expect(Strength::e1rm(100, 5))->toBe(116.7)->and(Strength::e1rm(100, 1))->toBe(100.0)->and(Strength::e1rm(100, 12))->toBe(140.0)
        ->and(Strength::e1rm(100, 13))->toBeNull()->and(Strength::e1rm(0, 5))->toBeNull()->and(Strength::e1rm(null, 5))->toBeNull()->and(Strength::e1rm(100, null))->toBeNull();
});

it('picks the best working set and ignores warm-ups', function () {
    $b = Strength::bestSet([['type' => 'warmup', 'kg' => 200, 'reps' => 5], ['type' => 'normal', 'kg' => 100, 'reps' => 5], ['type' => 'failure', 'kg' => 90, 'reps' => 9]]);
    expect($b)->toBe(['kg' => 90.0, 'reps' => 9, 'e1rm' => 117.0, 'rir' => 0])->and(Strength::bestSet([['type' => 'warmup', 'kg' => 50, 'reps' => 5]]))->toBeNull();
});

it('classifies progress honestly and refuses to guess with little data', function () {
    $h = fn (array $v) => array_map(fn ($e, $i) => ['date' => date('Y-m-d', strtotime("2026-10-01 +" . ($i * 7) . ' days')), 'e1rm' => $e], $v, array_keys($v));
    expect(Strength::status($h([100, 102]))['status'])->toBe('not_enough_data')
        ->and(Strength::status($h([100, 102, 104]))['status'])->toBe('progressing')
        ->and(Strength::status($h([100, 100, 100]))['status'])->toBe('holding')
        ->and(Strength::status($h([100, 105, 105, 105, 105]))['status'])->toBe('stalled')
        ->and(Strength::status($h([110, 105, 105, 105]))['status'])->toBe('holding')    // only 4 sessions: not enough to call a stall
        ->and(Strength::status($h([110, 100, 100, 100]))['status'])->toBe('down');
});

it('does not call a stall inside two weeks', function () {
    $h = array_map(fn ($e, $i) => ['date' => date('Y-m-d', strtotime("2026-10-01 +$i days")), 'e1rm' => $e], [105, 105, 105, 105, 105], range(0, 4));
    expect(Strength::status($h)['status'])->toBe('holding');
});

it('suggests beating the log by a rep or a load step', function () {
    expect(Strength::beatTheLog(['kg' => 100.0, 'reps' => 8]))->toBe([['kg' => 100.0, 'reps' => 9, 'label' => 'One more rep at the same weight'], ['kg' => 102.5, 'reps' => 8, 'label' => 'A small load step at the same reps']])
        ->and(Strength::beatTheLog(['kg' => 20.0, 'reps' => 10], 1.25)[1]['kg'])->toBe(21.25);
});

it('counts weekly working sets by muscle, secondaries at half', function () {
    $s = [sx('2026-11-23', 'press', [[60, 8, 'warmup'], [100, 8], [100, 7], [100, 6]], 'chest', ['triceps']), sx('2026-11-30', 'press', [[100, 8]], 'chest')];
    expect(Strength::weeklySets($s, '2026-11-23', '2026-11-29'))->toBe(['chest' => 3, 'triceps' => 1.5])
        ->and(Strength::weeklySets($s, '2026-12-01', '2026-12-07'))->toBe([]);
});

it('compares a session with your own earlier best', function () {
    $s = [sx('2026-11-02', 'a', [[100, 8]]), sx('2026-11-09', 'a', [[100, 8]]), sx('2026-11-16', 'a', [[105, 8]])];
    $r = Strength::sessionIndex($s, '2026-11-16');
    expect($r['beat'])->toBe(1)->and($r['compared'])->toBe(1)->and($r['index'])->toBe(1.05);
    expect(Strength::sessionIndex($s, '2026-11-02')['index'])->toBeNull()->and(Strength::sessionIndex($s, '2026-01-01')['compared'])->toBe(0);
    $down = Strength::sessionIndex([sx('2026-11-02', 'a', [[100, 8]]), sx('2026-11-09', 'a', [[90, 8]])], '2026-11-09');
    expect($down['down'])->toBe(1)->and($down['index'])->toBe(0.9);
});

it('gives a median strength change over lifts with at least 4 sessions, and nothing otherwise', function () {
    $mk = fn (string $id, array $kg) => array_map(fn ($k, $i) => sx(date('Y-m-d', strtotime("2026-10-01 +" . ($i * 7) . ' days')), $id, [[$k, 10]]), $kg, array_keys($kg));
    $s = array_merge($mk('a', [100, 100, 110, 110]), $mk('b', [50, 50, 50, 50]), $mk('c', [20, 25, 30]));
    expect(Strength::strengthChange($s))->toBe(['change_pct' => 5.0, 'exercises' => 2]);   // +10% and 0% -> median 5%
    expect(Strength::strengthChange($mk('c', [20, 25, 30])))->toBe(['change_pct' => null, 'exercises' => 0]);
});

it('maps a Hevy workout, tolerating gaps, and puts it on the right local day', function () {
    $w = ['id' => 'w1', 'title' => 'Chest', 'start_time' => '2026-11-22T16:30:00Z', 'end_time' => '2026-11-22T17:15:00Z', 'exercises' => [
        ['index' => 0, 'title' => 'Bench', 'exercise_template_id' => 'T1', 'sets' => [['index' => 0, 'type' => 'warmup', 'weight_kg' => 40, 'reps' => 10], ['index' => 1, 'type' => 'weird', 'weight_kg' => null, 'reps' => 8, 'rpe' => 9]]]]];
    $m = HevyMapper::workout($w, 'Australia/Melbourne');
    expect($m['local_date'])->toBe('2026-11-23')->and($m['minutes'])->toBe(45)->and($m['sets'])->toHaveCount(2)->and($m['sets'][1]['type'])->toBe('normal')->and($m['sets'][1]['weight_kg'])->toBeNull()->and($m['sets'][1]['rpe'])->toBe(9.0);
    // 01:30 local is still the previous day (03:00 rollover)
    expect(HevyMapper::workout(['id' => 'w2', 'start_time' => '2026-11-22T14:30:00Z'], 'Australia/Melbourne')['local_date'])->toBe('2026-11-22');
    expect(HevyMapper::workout(['title' => 'x'], 'UTC'))->toBeNull()->and(HevyMapper::workout(['id' => 'z', 'start_time' => 'nonsense'], 'UTC'))->toBeNull();
    expect(HevyMapper::template(['id' => 'T1', 'title' => 'Bench', 'primary_muscle_group' => 'chest', 'secondary_muscle_groups' => ['triceps']]))->toBe(['template_id' => 'T1', 'title' => 'Bench', 'muscle' => 'chest', 'secondary' => ['triceps']]);
});

it('adds reps in reserve to the estimate only when effort was logged', function () {
    // 100 kg x 8: Epley with 0 reserve = 126.7; with 2 reps in reserve it is as if 10 reps = 133.3
    expect(Strength::e1rm(100, 8))->toBe(126.7)->and(Strength::e1rm(100, 8, 2))->toBe(133.3)->and(Strength::e1rm(100, 11, 2))->toBeNull();   // 13 > the 12-rep cap
    expect(Strength::rir(['type' => 'failure']))->toBe(0)->and(Strength::rir(['type' => 'normal', 'rpe' => 10.0]))->toBe(0)->and(Strength::rir(['type' => 'normal', 'rpe' => 8.0]))->toBe(2)
        ->and(Strength::rir(['type' => 'normal', 'rpe' => 3.0]))->toBeNull()->and(Strength::rir(['type' => 'normal']))->toBeNull();
});

it('lets an RPE-8 set count for more than the same set unlabelled, and never inflates unlabelled sets', function () {
    $plain = Strength::bestSet([['type' => 'normal', 'kg' => 100, 'reps' => 8]]);
    $rpe8 = Strength::bestSet([['type' => 'normal', 'kg' => 100, 'reps' => 8, 'rpe' => 8]]);
    $fail = Strength::bestSet([['type' => 'failure', 'kg' => 100, 'reps' => 8]]);
    expect($plain['e1rm'])->toBe(126.7)->and($plain['rir'])->toBeNull()->and($rpe8['e1rm'])->toBe(133.3)->and($rpe8['rir'])->toBe(2)->and($fail['e1rm'])->toBe(126.7)->and($fail['rir'])->toBe(0);
});

it('summarises effort without guessing at unlabelled sets', function () {
    $e = Strength::effort([['type' => 'warmup', 'kg' => 40, 'reps' => 10], ['type' => 'normal', 'kg' => 100, 'reps' => 8, 'rpe' => 7.0], ['type' => 'normal', 'kg' => 100, 'reps' => 8, 'rpe' => 8.0], ['type' => 'failure', 'kg' => 100, 'reps' => 9], ['type' => 'normal', 'kg' => 100, 'reps' => 5]]);
    expect($e)->toBe(['working' => 4, 'failure' => 1, 'rpe_logged' => 2, 'unlabelled' => 1, 'avg_rpe' => 7.5]);
});
