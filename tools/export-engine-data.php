<?php
declare(strict_types=1);
// Exports real engine output to web-prototype/src/engine-data.json so the prototype UI shows true numbers
// (no formula duplication in TypeScript). Run: php tools/export-engine-data.php
require __DIR__ . '/../tests/Engine/bootstrap.php';
use App\Domain\Nutrition\{ExerciseCalculator as X, MacroPlanner as M, PeriWorkout as P};

$kg = 74.0; $bf = 14.0;
$week = [
  'mon' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60], ['type'=>'lifting','intensity'=>'heavy','minutes'=>50]],
  'tue' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60]],
  'wed' => [['type'=>'lifting','intensity'=>'heavy','minutes'=>50]],
  'thu' => [['type'=>'bjj','intensity'=>'standard','minutes'=>60]],
  'fri' => [['type'=>'lifting','intensity'=>'heavy','minutes'=>50], ['type'=>'norwegian_4x4','intensity'=>'standard','minutes'=>40]],
  'sat' => [['type'=>'bjj','intensity'=>'standard','minutes'=>90]],
  'sun' => [],
];
$goals = ['bulk' => 3150, 'maintain' => 2900, 'cut' => 2450];
$slim = fn(array $d) => ['class'=>$d['class'], 'kcal'=>(int)round($d['kcal']), 'protein'=>(int)round($d['protein']), 'carbs'=>(int)round($d['carbs']),
    'fat'=>(int)round($d['fat']), 'carbGPerKg'=>round($d['carbGPerKg'],1), 'netKcal'=>(int)round($d['netKcal'])];
$base = fn(array $over = []) => array_replace(['weightKg'=>$kg,'bodyFatPct'=>$bf,'phase'=>'bulk','kcalTarget'=>3150,'week'=>$week,'proteinGPerKg'=>2.2], $over);

$plans = [];
foreach ($goals as $g => $kcal) foreach (['flexible','cap'] as $mode) foreach ([1.8, 2.2, 2.6] as $pr) {
    $plan = M::plan($base(['phase'=>$g,'kcalTarget'=>$kcal,'carbMode'=>$mode,'carbCapG'=>$mode==='cap'?300:null,'proteinGPerKg'=>$pr]));
    $plans["$g|$mode|$pr"] = ['days' => array_map($slim, $plan['days']), 'weekly' => $plan['weekly'],
        'warnings' => array_map(fn($w)=>['level'=>$w['level'],'code'=>$w['code'],'msg'=>$w['msg']], $plan['warnings'])];
}

$cat = []; $sessions = [];
foreach (X::CATALOGUE as $t => $def) {
    $cat[$t] = ['label'=>$def['label'], 'intensities'=>array_map(fn($i)=>['met'=>$i['met'],'glycolytic'=>$i['glycolytic']], $def['intensities'])];
    foreach ($def['intensities'] as $i => $_) foreach ([15,30,40,45,50,60,75,90,120] as $min) {
        $s = X::session($t, $i, $min, $kg);
        $sessions["$t|$i|$min"] = ['net'=>(int)round($s['netKcal']),'low'=>(int)round($s['low']),'high'=>(int)round($s['high']),
            'peri'=>P::plan($kg, ['type'=>$t,'intensity'=>$i,'minutes'=>$min], 2.0, null, null)];
    }
}

// "Add an unplanned session on day D": re-plan the week (bulk, flexible, 2.2 g/kg) and compare that day.
$baseline = M::plan($base());
$adjust = [];
foreach (M::DAYS as $d) foreach (X::CATALOGUE as $t => $def) foreach ($def['intensities'] as $i => $_) foreach ([30,45,60,90] as $min) {
    $w = $week; $w[$d][] = ['type'=>$t,'intensity'=>$i,'minutes'=>$min];
    $after = M::plan($base(['week'=>$w]));
    $adjust["$d|$t|$i|$min"] = ['before'=>$slim($baseline['days'][$d]), 'after'=>$slim($after['days'][$d]),
        'othersKcalDelta'=>(int)round(array_sum(array_column($after['days'],'kcal')) - array_sum(array_column($baseline['days'],'kcal')))];
}

$out = ['meta'=>['weightKg'=>$kg,'bodyFatPct'=>$bf,'week'=>$week,'goals'=>$goals], 'catalogue'=>$cat, 'plans'=>$plans, 'sessions'=>$sessions, 'adjust'=>$adjust];
$path = __DIR__ . '/../web-prototype/src/engine-data.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES));
printf("plans=%d sessions=%d adjust=%d bytes=%d\n", count($plans), count($sessions), count($adjust), filesize($path));
