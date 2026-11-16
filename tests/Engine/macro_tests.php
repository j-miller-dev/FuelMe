<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use App\Domain\Nutrition\{ExerciseCalculator as X, MacroPlanner as M, PeriWorkout as P};

$fails = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $fails; echo ($ok ? '  ok  ' : ' FAIL ') . $name . ($extra ? "  [$extra]" : '') . "\n"; if (!$ok) $fails++; }

// Exercise maths
$bjj = X::session('bjj', 'standard', 60, 74);
check('BJJ 60min @74kg net = (7.5-1)*74 = 481', (int)$bjj['netKcal'] === 481, (string)$bjj['netKcal']);
check('range is +/-25%', $bjj['low'] < $bjj['netKcal'] && $bjj['high'] > $bjj['netKcal']);

$week = [
  'mon' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60], ['type'=>'lifting','intensity'=>'heavy','minutes'=>50]],
  'tue' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60]],
  'wed' => [['type'=>'lifting','intensity'=>'heavy','minutes'=>50]],
  'thu' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60]],
  'fri' => [['type'=>'lifting','intensity'=>'heavy','minutes'=>50], ['type'=>'norwegian_4x4','intensity'=>'standard','minutes'=>40]],
  'sat' => [['type'=>'bjj','intensity'=>'standard','minutes'=>90]],
  'sun' => [],
];
$in = ['weightKg'=>74.0,'bodyFatPct'=>14.0,'phase'=>'bulk','kcalTarget'=>3150,'week'=>$week,'proteinGPerKg'=>2.2];
$plan = M::plan($in);

$sum = array_sum(array_column($plan['days'], 'kcal'));
check('weekly kcal conserved (|diff| <= 30)', abs($sum - 7*3150) <= 30, "sum=$sum vs ".(7*3150));
$w = $plan['weekly'];
check('protein 2.2 g/kg honoured', abs($w['proteinGPerKg'] - 2.2) < 0.02, (string)$w['proteinGPerKg']);
check('fat >= 0.6 g/kg floor', $w['fatGPerKg'] >= 0.6, (string)$w['fatGPerKg']);
$cs = array_column($plan['days'], 'carbs');
check('hard day carbs > rest day carbs', $plan['days']['sat']['carbs'] > $plan['days']['sun']['carbs'], "sat={$plan['days']['sat']['carbs']} sun={$plan['days']['sun']['carbs']}");
check('no negative macros', min($cs) >= 0 && $w['fat'] > 0);
check('carb g/kg stays within 3-12', min(array_column($plan['days'],'carbGPerKg')) >= 3.0 && max(array_column($plan['days'],'carbGPerKg')) <= 12.0);

// Aggressive cut that should trip guardrails
$cut = M::plan(['weightKg'=>74.0,'bodyFatPct'=>12.0,'phase'=>'cut','kcalTarget'=>1700,'week'=>$week]);
$codes = array_column($cut['warnings'], 'code');
check('unsafe cut is raised to energy-availability floor', in_array('energy_floor', $codes, true) && $cut['weekly']['kcalTargetUsed'] > 1700, (string)$cut['weekly']['kcalTargetUsed']);

// Keto mode flags training conflict
$keto = M::plan($in + ['carbMode'=>'keto']);
check('keto mode: carbs 30 g and warning', $keto['days']['mon']['carbs'] == 30 && in_array('keto_training', array_column($keto['warnings'],'code'), true));

// Peri-workout
$pw = P::plan(74.0, ['type'=>'bjj','intensity'=>'standard','minutes'=>60], 2.0, 5.0, ['type'=>'lifting','intensity'=>'heavy','minutes'=>50]);
check('pre carbs 1-4 g/kg window', $pw['pre']['carbsG'] >= 74 && $pw['pre']['carbsG'] <= 296, (string)$pw['pre']['carbsG']);
check('post protein 20-50 g', $pw['post']['proteinG'] >= 20 && $pw['post']['proteinG'] <= 50, (string)$pw['post']['proteinG']);
$pw2 = P::plan(74.0, ['type'=>'bjj','intensity'=>'hard','minutes'=>60], 2.0, 6.0, ['type'=>'hiit','intensity'=>'moderate','minutes'=>30]);
check('glycolytic double day -> 1.0-1.2 g/kg/h', ($pw2['post']['carbsGPerHour'][0] ?? 0) == 74 && $pw2['post']['carbsGPerHour'][1] == 89);

// Print worked example
echo "\n--- Worked example: 74 kg, 14% BF, lean bulk, 3150 kcal avg, 2.2 g/kg protein ---\n";
printf("%-4s %-9s %5s %5s %4s %5s %6s %5s %4s\n", 'Day','Class','kcal','P','F','C','C g/kg','Train','EA');
foreach ($plan['days'] as $d => $r) printf("%-4s %-9s %5d %5d %4d %5d %6.1f %5d %4.0f\n", $d, $r['class'], $r['kcal'], $r['protein'], $r['fat'], $r['carbs'], $r['carbGPerKg'], $r['netKcal'], $r['energyAvailability']);
echo "\nweekly: " . json_encode($plan['weekly']) . "\n";
echo "warnings: " . json_encode(array_column($plan['warnings'],'code')) . "\n";
echo "\nPeri (Mon BJJ, meal 2h before, lift in 5h): " . json_encode($pw) . "\n";
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails ? 1 : 0);
