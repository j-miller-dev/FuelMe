<?php
declare(strict_types=1);
require __DIR__ . '/../Engine/bootstrap.php';
use App\Domain\Nutrition\GoalPlanner as G;
$fail = 0;
function check(string $n, bool $ok, string $x = ''): void { global $fail; echo ($ok ? "ok   " : "FAIL ") . $n . ($x ? "  [$x]" : '') . "\n"; if (!$ok) $fail++; }

// 74 kg, +2 kg in 16 weeks = 0.169 %/wk -> on track for any experience
$a = G::assess(74.0, 76.0, 112, 'intermediate', 2950);
check('+2 kg / 16 wk on track', $a['status'] === 'on_track' && abs($a['ratePctPerWeek'] - 0.169) < 0.005, json_encode([$a['status'], $a['ratePctPerWeek']]));
check('surplus = 2 kg x 7700 / 112 d = 138 kcal/day', $a['balanceKcalPerDay'] == 138.0 && $a['kcalTarget'] === 3088, (string)$a['balanceKcalPerDay']);
// +3 kg in 6 weeks = 0.676 %/wk: over intermediate cap 0.35 (<2x? 0.676 <= 0.70 -> too_fast)
$b = G::assess(74.0, 77.0, 42, 'intermediate', 2950);
check('+3 kg / 6 wk too fast for intermediate', $b['status'] === 'too_fast', json_encode([$b['ratePctPerWeek'], $b['status']]));
check('too-fast plan uses the cap, not the ask', abs($b['planRatePctPerWeek'] - 0.35) < 1e-9);
check('earliest safe date given', $b['earliestDays'] === (int) ceil(3 / (0.0035 * 74) * 7));
$c = G::assess(74.0, 80.0, 28, 'advanced', 2950);
check('+6 kg / 4 wk not achievable', $c['status'] === 'not_achievable');
$d = G::assess(80.0, 76.0, 56, 'intermediate', 2900);   // -0.625 %/wk
check('cut -4 kg / 8 wk on track (<=1.0)', $d['status'] === 'on_track' && $d['direction'] === 'loss' && $d['balanceKcalPerDay'] < 0);
$e = G::assess(80.0, 70.0, 28, 'intermediate', 2900);   // -3.1 %/wk
check('-10 kg / 4 wk not achievable', $e['status'] === 'not_achievable');
$f = G::assess(74.0, 74.02, 90, 'intermediate', 2950);
check('maintain', $f['direction'] === 'maintain' && $f['balanceKcalPerDay'] == 0.0 && $f['kcalTarget'] === 2950);
check('achievableKgByDate respects cap', abs($b['achievableKgByDate'] - (74 + 0.0035 * 74 * 6)) < 0.01);

$m = G::fromMuscle(2.0, 0.5, 74.0, 168, 'intermediate');  // 2 kg muscle in 24 wk -> 4 kg weight
check('muscle goal -> weight target 78 kg', $m['targetKg'] === 78.0);
check('2 kg muscle / 5.5 mo = 0.36 kg/mo, within intermediate ceiling', $m['muscleStatus'] === 'within', json_encode($m));
$m2 = G::fromMuscle(4.0, 0.5, 74.0, 90, 'intermediate');  // 4 kg in 3 mo -> 1.35 kg/mo = 1.8 % -> ambitious
check('4 kg muscle / 3 mo flagged ambitious', $m2['muscleStatus'] === 'ambitious');
echo $fail ? "FAILED $fail\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
