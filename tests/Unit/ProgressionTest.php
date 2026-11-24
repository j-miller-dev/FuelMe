<?php

use App\Domain\Training\Progression;
use App\Domain\Training\RoutineDiff;

function pgSets(array $s): array
{
    return array_map(fn ($x) => ['type' => $x[2] ?? 'normal', 'kg' => $x[0], 'reps' => $x[1], 'rpe' => $x[3] ?? null], $s);
}

function pgPlan(int $sets, int $lo, int $hi, ?float $kg = null): array
{
    return array_fill(0, $sets, ['type' => 'normal', 'kg' => $kg, 'reps' => null, 'rep_min' => $lo, 'rep_max' => $hi]);
}

function pgRec(array $sessions, ?array $plan, float $step = 2.5, ?array $effort = null, string $today = '2026-11-24', ?string $status = null): array
{
    return Progression::recommend(array_map(fn ($s) => ['date' => $s[0], 'sets' => pgSets($s[1])], $sessions), $plan, $step, $effort, $today, $status);
}

it('goes up one jump when every top-weight set reached the top of the range (hand-checked)', function () {
    $r = pgRec([['2026-11-20', [[80, 10], [80, 10], [80, 10]]]], pgPlan(3, 8, 10));
    // Epley 80 x 10 = 106.7; at 82.5 kg with 1 rep in reserve (no effort plan): 30 * (106.7 / 82.5 - 1) - 1 = 7.8 -> 7 reps
    expect($r['action'])->toBe('increase')->and($r['kg'])->toBe(82.5)->and($r['reps_low'])->toBe(8)->and($r['reps_high'])->toBe(10)->and($r['expected_reps'])->toBe(7)->and($r['jump_pct'])->toBe(3.1)
        ->and($r['confidence'])->toBe('reps only')->and(implode(' ', $r['notes']))->toContain('below the bottom of the range')->toContain('did not log effort');
    // with your effort plan 2-3 in reserve the expectation drops: 8.8 - 2.5 = 6.3 -> 6
    expect(pgRec([['2026-11-20', [[80, 10], [80, 10], [80, 10]]]], pgPlan(3, 8, 10), 2.5, ['min' => 2, 'max' => 3])['expected_reps'])->toBe(6);
});

it('holds and adds a rep when you are inside the range but not at the top on every set', function () {
    $r = pgRec([['2026-11-20', [[80, 10], [80, 9], [80, 8]]]], pgPlan(3, 8, 10));
    expect($r['action'])->toBe('hold')->and($r['kg'])->toBe(80.0)->and($r['reps_low'])->toBe(9)->and($r['reps_high'])->toBe(10)->and($r['reason'])->toContain('weakest set')->toContain('8 reps');
});

it('repeats once below the bottom of the range, then drops about 5% if it happens again', function () {
    $once = pgRec([['2026-11-20', [[80, 7], [80, 7], [80, 6]]]], pgPlan(3, 8, 10));
    expect($once['action'])->toBe('repeat')->and($once['kg'])->toBe(80.0);
    $twice = pgRec([['2026-11-13', [[80, 7], [80, 7]]], ['2026-11-20', [[80, 7], [80, 6]]]], pgPlan(3, 8, 10));
    // 5% off 80 = 76, rounded down to the 2.5 jump = 75
    expect($twice['action'])->toBe('reduce')->and($twice['kg'])->toBe(75.0)->and($twice['reason'])->toContain('two sessions in a row');
    // a lighter earlier session is not a miss at the same weight
    expect(pgRec([['2026-11-13', [[70, 6]]], ['2026-11-20', [[80, 7]]]], pgPlan(3, 8, 10))['action'])->toBe('repeat');
});

it('repeats the last weight after a layoff instead of jumping', function () {
    $r = pgRec([['2026-10-10', [[80, 10], [80, 10]]]], pgPlan(2, 8, 10));
    expect($r['action'])->toBe('repeat')->and($r['kg'])->toBe(80.0)->and($r['reason'])->toContain('45 days');
});

it('needs a rep range to say when to go up', function () {
    $r = pgRec([['2026-11-20', [[80, 10], [80, 10]]]], null);
    expect($r['action'])->toBe('hold')->and($r['reps_low'])->toBe(11)->and($r['reason'])->toContain('no rep range');
});

it('judges only the top-weight sets and ignores warm-ups and back-off sets', function () {
    $r = pgRec([['2026-11-20', [[60, 12, 'warmup'], [80, 10], [80, 10], [70, 15]]]], pgPlan(3, 8, 10));
    expect($r['action'])->toBe('increase')->and($r['last'])->toMatchArray(['kg' => 80.0, 'sets_at_top' => 2]);
});

it('starts at the routine weight when you have never logged it, and says so for timed or bodyweight work', function () {
    $s = pgRec([], pgPlan(3, 8, 10, 60.0));
    expect($s['action'])->toBe('start')->and($s['kg'])->toBe(60.0);
    $n = pgRec([], [['type' => 'normal', 'kg' => null, 'reps' => null, 'rep_min' => null, 'rep_max' => null]]);
    expect($n['action'])->toBe('no_load')->and($n['kg'])->toBeNull();
    // sets with no weight (a jump rope, a bodyweight set) are not progressed
    expect(pgRec([['2026-11-20', [[null, 30], [0, 12]]]], null)['action'])->toBe('no_load');
});

it('reads effort when you logged it, and never guesses when you did not', function () {
    $f = pgRec([['2026-11-20', [[80, 10, 'failure'], [80, 10, 'failure']]]], pgPlan(2, 8, 10));
    expect($f['confidence'])->toBe('effort logged')->and($f['last_avg_rir'])->toBe(0.0);
    $r = pgRec([['2026-11-20', [[80, 10, 'normal', 9], [80, 10, 'normal', 8]]]], pgPlan(2, 8, 10));
    expect($r['last_avg_rir'])->toBe(1.5)->and($r['confidence'])->toBe('effort logged');
    $u = pgRec([['2026-11-20', [[80, 10], [80, 10]]]], pgPlan(2, 8, 10));
    expect($u['last_avg_rir'])->toBeNull()->and($u['confidence'])->toBe('reps only');
});

it('warns about a big jump and about a stalled lift, but leaves the decision to you', function () {
    $big = pgRec([['2026-11-20', [[20, 12], [20, 12]]]], pgPlan(2, 10, 12), 2.5);
    expect($big['action'])->toBe('increase')->and($big['jump_pct'])->toBe(12.5)->and(implode(' ', $big['notes']))->toContain('12.5%');
    $st = pgRec([['2026-11-20', [[80, 9], [80, 9]]]], pgPlan(2, 8, 10), 2.5, null, '2026-11-24', 'stalled');
    expect(implode(' ', $st['notes']))->toContain('stalled')->toContain('your call');
});

it('picks a default jump from the exercise name and lets the fallback apply otherwise', function () {
    $steps = ['dumbbell' => 2.0, 'barbell' => 2.5, 'machine' => 2.5];
    expect(Progression::defaultStep('Incline Bench Press (Dumbbell)', $steps, 2.5))->toBe(2.0)->and(Progression::defaultStep('Squat (Barbell)', $steps, 1.0))->toBe(2.5)
        ->and(Progression::defaultStep('Chest Supported T Bar Row', $steps, 1.25))->toBe(1.25);
});

it('runs Epley backwards for expected reps and a working weight (hand-checked)', function () {
    expect(Progression::expectedReps(106.7, 82.5, 1))->toBe(7)->and(Progression::expectedReps(100, 100, 0))->toBe(0)->and(Progression::expectedReps(100, 0, 0))->toBe(0);
    // 106.7 / (1 + (8 + 1) / 30) = 82.08 kg, rounded DOWN to the 2.5 jump = 80
    expect(Progression::weightFor(106.7, 8, 1, 2.5))->toBe(80.0);
});

it('projects the trend as a range, capped, and refuses when there is not enough or it is flat', function () {
    $h = array_map(fn ($i) => ['date' => date('Y-m-d', strtotime("2026-10-06 +{$i} weeks")), 'e1rm' => 100.0 + 2 * $i], range(0, 5));   // +2 kg a week, perfectly straight
    $p = Progression::projection($h, 8, 2.5, null);
    // 4 weeks: full trend = 110 + 8 = 118 but the 4%/month cap is 114.4; half trend = 114
    expect($p['status'])->toBe('ok')->and($p['slope_per_week'])->toBe(2.0)->and($p['fit'])->toBe(1.0)->and($p['latest'])->toBe(110.0)
        ->and($p['weeks'][0])->toMatchArray(['weeks' => 4, 'e1rm_low' => 114.0, 'e1rm_high' => 114.4, 'kg_low' => 87.5, 'kg_high' => 87.5])
        ->and($p['weeks'][1])->toMatchArray(['weeks' => 8, 'e1rm_low' => 118.0, 'e1rm_high' => 118.8]);

    expect(Progression::projection(array_slice($h, 0, 3), 8, 2.5, null)['status'])->toBe('not_enough_data');
    $tight = array_map(fn ($i) => ['date' => date('Y-m-d', strtotime("2026-11-01 +{$i} days")), 'e1rm' => 100.0 + $i], range(0, 4));   // 4 points but only 4 days
    expect(Progression::projection($tight, 8, 2.5, null)['status'])->toBe('not_enough_data');
    $flat = array_map(fn ($i) => ['date' => date('Y-m-d', strtotime("2026-10-06 +{$i} weeks")), 'e1rm' => 100.0], range(0, 5));
    $f = Progression::projection($flat, 8, 2.5, null);
    expect($f['status'])->toBe('flat_or_down')->and($f['weeks'])->toBe([]);
    $noisy = array_map(fn ($i, $v) => ['date' => date('Y-m-d', strtotime("2026-10-06 +{$i} weeks")), 'e1rm' => (float) $v], range(0, 5), [100, 110, 98, 108, 99, 107]);
    expect(Progression::projection($noisy, 8, 2.5, null)['status'])->toBe('noisy');
});

it('describes a session against its routine: skipped, partial, extra', function () {
    $planned = [
        ['template_id' => 'SQ', 'title' => 'Squat', 'planned' => pgPlan(3, 5, 8)],
        ['template_id' => 'BP', 'title' => 'Bench', 'planned' => pgPlan(3, 8, 10)],
        ['template_id' => 'RW', 'title' => 'Row', 'planned' => pgPlan(2, 8, 10)],
        ['template_id' => '', 'title' => 'Jump Rope', 'planned' => [['type' => 'normal']]],
    ];
    $done = [
        ['id' => 'SQ', 'title' => 'Squat', 'sets' => pgSets([[100, 5], [100, 5], [100, 5], [60, 10, 'warmup']])],
        ['id' => 'BP', 'title' => 'Bench', 'sets' => pgSets([[80, 8]])],
        ['id' => 'CU', 'title' => 'Curl', 'sets' => pgSets([[20, 12]])],
        ['id' => '', 'title' => 'jump rope', 'sets' => pgSets([[null, null]])],
    ];
    $d = RoutineDiff::compare($planned, $done);
    expect(array_column($d['rows'], 'status', 'title'))->toBe(['Squat' => 'done', 'Bench' => 'partial', 'Row' => 'skipped', 'Jump Rope' => 'done', 'Curl' => 'extra'])
        ->and($d)->toMatchArray(['skipped' => 1, 'partial' => 1, 'extra' => 1, 'done' => 2])
        ->and($d['rows'][1])->toMatchArray(['planned_sets' => 3, 'done_sets' => 1]);
});
