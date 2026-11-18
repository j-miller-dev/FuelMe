<?php
declare(strict_types=1);
require __DIR__ . '/../Engine/bootstrap.php';
use App\Domain\Nutrition\DayOnePrior as P;
$fail = 0;
function check(string $n, bool $ok, string $x = ''): void { global $fail; echo ($ok ? "ok   " : "FAIL ") . $n . ($x ? "  [$x]" : '') . "\n"; if (!$ok) $fail++; }

// Mifflin-St Jeor, male 74 kg / 180 cm / 30 y: 740 + 1125 - 150 + 5 = 1720
$m = P::rmr(74.0, 180.0, 30, 'male');
check('Mifflin male 74/180/30 = 1720', $m['kcal'] == 1720.0 && $m['method'] === 'mifflin_st_jeor', json_encode($m));
$f = P::rmr(60.0, 165.0, 30, 'female');  // 600 + 1031.25 - 150 - 161 = 1320.25
check('Mifflin female 60/165/30 = 1320.25', abs($f['kcal'] - 1320.25) < 0.001, (string)$f['kcal']);

// Katch-McArdle when body fat known: FFM = 74 x 0.86 = 63.64 -> 370 + 21.6 x 63.64 = 1744.6
$k = P::rmr(74.0, 180.0, 30, 'male', 14.0);
check('Katch-McArdle at 14% BF = 1744.6', $k['method'] === 'katch_mcardle' && abs($k['kcal'] - 1744.624) < 0.01, json_encode($k));
check('implausible body fat (0 or >=60) falls back to Mifflin', P::rmr(74.0, 180.0, 30, 'male', 0.0)['method'] === 'mifflin_st_jeor' && P::rmr(74.0, 180.0, 30, 'male', 65.0)['method'] === 'mifflin_st_jeor');

// No training: 1720 x 1.5 (on feet) = 2580
$e = P::estimate(74.0, 180.0, 30, 'male', null, 'on_feet', []);
check('no training, on-feet job = 2580', $e['tdee'] == 2580.0 && $e['exerciseKcalPerDay'] == 0.0 && $e['pal'] === 1.5, json_encode($e));
check('prior carries sigma 350', $e['sd'] === 350.0);

// One 60 min standard BJJ at 74 kg = 481 net kcal/week = 68.7/day -> 2580 + 69 = 2649
$e2 = P::estimate(74.0, 180.0, 30, 'male', null, 'on_feet', ['mon' => [['type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60]]]);
check('exercise adds weekly net / 7 (481/7 = 69)', $e2['tdee'] == 2649.0 && $e2['exerciseKcalPerDay'] == 69.0, json_encode($e2));

// Unknown job -> default PAL, flagged as assumed (UI shows it)
$e3 = P::estimate(74.0, 180.0, 30, 'male', null, 'astronaut', []);
check('unknown job uses default PAL and is flagged', $e3['pal'] === P::JOB_PAL[P::DEFAULT_JOB] && $e3['palAssumed'] === true);

// Monotonic in job demand
$desk = P::estimate(74.0, 180.0, 30, 'male', null, 'desk', [])['tdee'];
$heavy = P::estimate(74.0, 180.0, 30, 'male', null, 'heavy', [])['tdee'];
check('heavy manual job > desk job', $heavy > $desk, "$heavy vs $desk");

// Age on a date (birthday boundary)
check('age before birthday', P::ageOn('1996-06-15', '2026-06-14') === 29);
check('age on birthday', P::ageOn('1996-06-15', '2026-06-15') === 30);
check('age later in year', P::ageOn('1996-06-15', '2026-11-18') === 30);

echo $fail ? "FAILED $fail\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
