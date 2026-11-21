<?php
declare(strict_types=1);
// BLUEPRINT 9A.1: rough and estimated days must not corrupt the expenditure estimate. Same simulated people as simulate.php, but a quarter of
// the days are "roughly on target" (the plan is recorded instead of what was eaten) and some are missing. Error must stay inside the 9.1 bounds +40.
require __DIR__ . '/bootstrap.php';
use App\Domain\Nutrition\ExpenditureFilter;
use App\Domain\Nutrition\TargetCalculator;

function gaussR(): float { return sqrt(-2 * log(max(mt_rand() / mt_getrandmax(), 1e-12))) * cos(2 * M_PI * mt_rand() / mt_getrandmax()); }
function chance(float $p): bool { return mt_rand() / mt_getrandmax() < $p; }

/** @return array{0:list<array>,1:list<array>} days with the three treatments of a rough day, and the truth */
function world(int $seed, float $roughShare, float $missShare, int $n = 90): array {
    mt_srand($seed);
    $trueW = 90.0; $sd = $exact = $skip = $truth = [];
    for ($i = 0; $i < $n; $i++) {
        $tdee = 2800 + 22 * ($trueW - 90) + ($i >= 45 ? -180 : 0);
        $eaten = 2300 + 250 * gaussR();
        $logged = $eaten * (1 + 0.04 * gaussR());
        $scale = $trueW + 0.6 * gaussR() + (chance(0.07) ? 1.5 + abs(gaussR()) : 0);
        $weight = chance(0.15) ? null : $scale;
        $date = date('Y-m-d', strtotime("2026-01-01 +$i day"));
        $r = chance($roughShare); $m = !$r && chance($missShare);
        // A rough day records the PLAN (2300), not what was really eaten.
        $obs = $m ? null : ($r ? 2300.0 : $logged);
        $base = ['date' => $date, 'weight' => $weight, 'intake' => $obs];
        $sd[] = $base + ($r ? ['intakeSd' => 350.0] : []);   // what the app does: use it, widely
        $exact[] = $base;                                      // the mistake: treat the guess as exact
        $skip[] = ['intake' => $r ? null : $obs] + $base;      // the alternative: throw rough days away
        $truth[] = $tdee;
        $trueW += ($eaten - $tdee) / 7700;
    }
    return [['sd' => $sd, 'exact' => $exact, 'skip' => $skip], $truth];
}

$prior = TargetCalculator::priorTdee(90, 180, 30, 'male', 1.4);
$f = new ExpenditureFilter();
$acc = [];
foreach (range(1, 40) as $seed) {
    [$sets, $truth] = world($seed, 0.25, 0.10);
    foreach ($sets as $name => $days) {
        foreach ($f->run($days, $days[0]['weight'] ?? 90.0, $prior) as $i => $r) {
            $phase = $i < 14 ? 'wk1-2' : ($i < 42 ? 'wk3-6' : ($i < 66 ? 'post' : 'late'));
            $acc[$name][$phase][] = abs($r['tdee'] - $truth[$i]);
        }
    }
}
$avg = fn($a) => array_sum($a) / count($a);
printf("%-8s %8s %8s %8s %8s   (mean |error| kcal, 25%% rough days + 10%% missing)\n", 'rough as', 'wk1-2', 'wk3-6', 'post', 'late');
foreach (['sd' => 'wide sd', 'exact' => 'exact', 'skip' => 'skipped'] as $k => $label) {
    printf("%-8s %8.0f %8.0f %8.0f %8.0f\n", $label, $avg($acc[$k]['wk1-2']), $avg($acc[$k]['wk3-6']), $avg($acc[$k]['post']), $avg($acc[$k]['late']));
}
// Bounds: BLUEPRINT 9.1 says wk3-6 < 150 and late < 160; rough days may cost at most 40 kcal on top.
$ok = $avg($acc['sd']['wk3-6']) < 150 + 40 && $avg($acc['sd']['late']) < 160 + 40
    && $avg($acc['sd']['wk3-6']) <= $avg($acc['exact']['wk3-6']) + 10   // widening never makes it worse than trusting the guess
    && $avg($acc['sd']['late']) <= $avg($acc['skip']['late']) + 10;     // and using rough days is no worse than throwing them away
echo $ok ? "\nPASS\n" : "\nFAIL\n";
exit($ok ? 0 : 1);
