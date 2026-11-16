<?php
declare(strict_types=1);
// Exports real engine output to web-prototype/src/engine-data.json so the prototype UI shows true numbers
// (no formula duplication in TypeScript). Run: php tools/export-engine-data.php
require __DIR__ . '/../tests/Engine/bootstrap.php';
use App\Domain\Nutrition\{ExerciseCalculator as X, GoalPlanner as G, MacroPlanner as M, PeriWorkout as P};

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
$liftHit = fn(array $day) => array_map(fn($x) => $x['type'] === 'lifting' ? ['type'=>'lifting','intensity'=>'hit','minutes'=>40] : $x, $day);
$weeks = ['volume' => $week, 'hit' => array_map($liftHit, $week)];
$goals = ['bulk' => 3150, 'maintain' => 2900, 'cut' => 2450];
$slim = fn(array $d) => ['class'=>$d['class'], 'kcal'=>(int)round($d['kcal']), 'protein'=>(int)round($d['protein']), 'carbs'=>(int)round($d['carbs']),
    'fat'=>(int)round($d['fat']), 'carbGPerKg'=>round($d['carbGPerKg'],1), 'netKcal'=>(int)round($d['netKcal'])];
$base = fn(array $over = [], string $style = 'hit') => array_replace(['weightKg'=>$kg,'bodyFatPct'=>$bf,'phase'=>'bulk','kcalTarget'=>3150,'week'=>$GLOBALS['weeks'][$style],'proteinGPerKg'=>2.2], $over);

$plans = [];
foreach (['hit','volume'] as $style) foreach ($goals as $g => $kcal) foreach (['flexible','cap'] as $mode) foreach ([1.8, 2.2, 2.6] as $pr) {
    $plan = M::plan($base(['phase'=>$g,'kcalTarget'=>$kcal,'carbMode'=>$mode,'carbCapG'=>$mode==='cap'?300:null,'proteinGPerKg'=>$pr], $style));
    $plans["$style|$g|$mode|$pr"] = ['days' => array_map($slim, $plan['days']), 'weekly' => $plan['weekly'],
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
$adjust = [];
foreach (['hit','volume'] as $style) {
    $baseline = M::plan($base([], $style));
    foreach (M::DAYS as $d) foreach (X::CATALOGUE as $t => $def) foreach ($def['intensities'] as $i => $_) foreach ([30,45,60,90] as $min) {
        $w = $weeks[$style]; $w[$d][] = ['type'=>$t,'intensity'=>$i,'minutes'=>$min];
        $after = M::plan($base(['week'=>$w], $style));
        $adjust["$style|$d|$t|$i|$min"] = ['before'=>$slim($baseline['days'][$d]), 'after'=>$slim($after['days'][$d]),
            'othersKcalDelta'=>(int)round(array_sum(array_column($after['days'],'kcal')) - array_sum(array_column($baseline['days'],'kcal')))];
    }
}

// Macro plans over a calorie grid so a goal-derived calorie target can be shown with real macros (nearest 50 kcal).
$grid = [];
foreach (['hit','volume'] as $style) foreach (['bulk','maintain','cut'] as $ph) for ($k = 2000; $k <= 3800; $k += 50) {
    $pl = M::plan($base(['phase'=>$ph,'kcalTarget'=>$k], $style));
    $grid["$style|$ph|$k"] = ['days'=>array_map($slim, $pl['days']), 'weekly'=>$pl['weekly'],
        'warnings'=>array_map(fn($w)=>['level'=>$w['level'],'code'=>$w['code'],'msg'=>$w['msg']], $pl['warnings'])];
}

// Parity fixtures: the TypeScript port of GoalPlanner is tested against these PHP results.
$cases = [];
foreach ([[74.0,76.0,112,'intermediate',2950],[74.0,77.0,42,'intermediate',2950],[74.0,80.0,28,'advanced',2950],[80.0,76.0,56,'intermediate',2900],
          [80.0,70.0,28,'intermediate',2900],[74.0,74.02,90,'intermediate',2950],[73.9,78.0,200,'novice',2980],[73.9,78.0,200,'advanced',2980]] as $c) {
    $cases[] = ['kind'=>'assess','in'=>$c,'out'=>G::assess(...$c)];
}
foreach ([[2.0,0.5,74.0,168,'intermediate'],[4.0,0.5,74.0,90,'intermediate'],[3.0,0.6,73.9,240,'advanced']] as $c) {
    $cases[] = ['kind'=>'muscle','in'=>$c,'out'=>G::fromMuscle(...$c)];
}
file_put_contents(__DIR__ . '/../web-prototype/src/goal-cases.json', json_encode($cases, JSON_UNESCAPED_SLASHES));

$out = ['meta'=>['weightKg'=>$kg,'bodyFatPct'=>$bf,'weeks'=>$weeks,'goals'=>$goals], 'catalogue'=>$cat, 'plans'=>$plans, 'grid'=>$grid, 'sessions'=>$sessions, 'adjust'=>$adjust];
$path = __DIR__ . '/../web-prototype/src/engine-data.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES));
printf("plans=%d sessions=%d adjust=%d bytes=%d\n", count($plans), count($sessions), count($adjust), filesize($path));
