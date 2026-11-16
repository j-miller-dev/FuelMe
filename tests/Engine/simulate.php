<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use App\Domain\Nutrition\ExpenditureFilter;
use App\Domain\Nutrition\TargetCalculator;

function gauss(): float { return sqrt(-2 * log(max(mt_rand() / mt_getrandmax(), 1e-12))) * cos(2 * M_PI * mt_rand() / mt_getrandmax()); }

/** Simulate a person with KNOWN true TDEE, then see if the filter recovers it. */
function simulate(int $seed, int $n = 90): array {
    mt_srand($seed);
    $kg = 90.0; $trueW = $kg; $days = []; $truth = [];
    for ($i = 0; $i < $n; $i++) {
        $tdee = 2800 + 22 * ($trueW - 90) + ($i >= 45 ? -180 : 0); // adapts to weight, plus activity drop at day 45
        $eaten = 2300 + 250 * gauss();                              // what they actually ate
        $logged = $eaten * (1 + 0.04 * gauss());                    // logging error
        $spike = (mt_rand() / mt_getrandmax() < 0.07) ? 1.5 + abs(gauss()) : 0; // salty-meal water
        $scale = $trueW + 0.6 * gauss() + $spike;
        $days[] = [
            'date' => date('Y-m-d', strtotime("2026-01-01 +$i day")),
            'weight' => (mt_rand() / mt_getrandmax() < 0.15) ? null : $scale, // 15% missed weigh-ins
            'intake' => (mt_rand() / mt_getrandmax() < 0.10) ? null : $logged, // 10% missed logs
            '_eaten' => $eaten,
        ];
        $truth[] = ['tdee' => $tdee, 'w' => $trueW];
        $trueW += ($eaten - $tdee) / 7700;
    }
    return [$days, $truth];
}

$f = new ExpenditureFilter();
$prior = TargetCalculator::priorTdee(90, 180, 30, 'male', 1.4); // deliberately a bit off
$errs = ['wk1-2' => [], 'wk3-6' => [], 'post-step 3wk' => [], 'late' => []];
$naiveErr = [];
$seeds = range(1, 40);
foreach ($seeds as $s) {
    [$days, $truth] = simulate($s);
    $res = $f->run($days, $days[0]['weight'] ?? 90.0, $prior);
    foreach ($res as $i => $r) {
        $err = abs($r['tdee'] - $truth[$i]['tdee']);
        if ($i < 14) $errs['wk1-2'][] = $err;
        elseif ($i < 42) $errs['wk3-6'][] = $err;
        elseif ($i < 66) $errs['post-step 3wk'][] = $err;
        else $errs['late'][] = $err;
    }
    // Naive baseline: avg logged intake - 14d scale change * 7700 / 14
    for ($i = 28; $i < 90; $i++) {
        $win = array_slice($days, $i - 14, 15);
        $ws = array_values(array_filter(array_column($win, 'weight'), fn($x) => $x !== null));
        $is = array_values(array_filter(array_column($win, 'intake'), fn($x) => $x !== null));
        if (count($ws) < 4 || count($is) < 8) continue;
        $naive = array_sum($is) / count($is) - (end($ws) - $ws[0]) * 7700 / 14;
        $naiveErr[] = abs($naive - $truth[$i]['tdee']);
    }
}
$avg = fn($a) => count($a) ? array_sum($a) / count($a) : NAN;
printf("Prior (formula) TDEE: %.0f kcal; true starts at 2800\n\n", $prior);
printf("%-16s %s\n", 'Phase', 'mean |error| kcal (40 simulated people)');
foreach ($errs as $k => $v) printf("%-16s %6.0f\n", $k, $avg($v));
printf("%-16s %6.0f   <- naive 14-day formula\n", 'naive (all)', $avg($naiveErr));

$ok = $avg($errs["wk3-6"]) < 150 && $avg($errs["late"]) < 160 && $avg($errs["late"]) * 2 < $avg($naiveErr);
echo $ok ? "\nPASS\n" : "\nFAIL\n";
exit($ok ? 0 : 1);
