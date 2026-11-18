<?php
declare(strict_types=1);
require __DIR__ . '/../Engine/bootstrap.php';
use App\Domain\Nutrition\GoalRates as R;
$fail = 0;
function check(string $n, bool $ok, string $x = ''): void { global $fail; echo ($ok ? "ok   " : "FAIL ") . $n . ($x ? "  [$x]" : '') . "\n"; if (!$ok) $fail++; }

check('experience mapping', R::experience('lt1') === 'novice' && R::experience('1to3') === 'intermediate' && R::experience('gt3') === 'advanced' && R::experience('?') === 'intermediate');
check('default rates', R::default('bulk', 'novice') == 0.5 && R::default('bulk', 'intermediate') == 0.3 && R::default('bulk', 'advanced') == 0.2 && R::default('cut', 'advanced') == -0.5 && R::default('maintain', 'novice') == 0.0);
check('every default passes its own check', R::check('bulk', 'novice', 0.5) === null && R::check('bulk', 'intermediate', 0.3) === null && R::check('bulk', 'advanced', 0.2) === null && R::check('cut', 'novice', -0.5) === null && R::check('maintain', 'novice', 0.0) === null);
check('bulk above experience ceiling rejected', R::check('bulk', 'advanced', 0.4) !== null && R::check('bulk', 'intermediate', 0.36) !== null);
check('bulk at the ceiling allowed', R::check('bulk', 'intermediate', 0.35) === null);
check('cut over 1.0 %/wk rejected, 1.0 allowed', R::check('cut', 'novice', -1.2) !== null && R::check('cut', 'novice', -1.0) === null);
check('wrong sign rejected', R::check('bulk', 'novice', -0.2) !== null && R::check('cut', 'novice', 0.2) !== null && R::check('maintain', 'novice', 0.1) !== null);
check('unknown phase rejected', R::check('shred', 'novice', -2.0) !== null);

// 74 kg / 180 cm -> BMI 22.8 -> cut allowed with no BF; BF 14% allowed; BF 9% blocked
check('cut allowed at BMI 22.8, no BF', R::cutBlockedReason(74.0, 180.0, null) === null);
check('cut allowed at 14% BF', R::cutBlockedReason(74.0, 180.0, 14.0) === null);
check('cut blocked at 9% BF', R::cutBlockedReason(74.0, 180.0, 9.0) !== null);
check('cut blocked at BMI 18.5, no BF', R::cutBlockedReason(60.0, 180.0, null) !== null);
check('known BF overrides BMI (muscular, low BMI but 15% BF is allowed)', R::cutBlockedReason(60.0, 180.0, 15.0) === null);

echo $fail ? "FAILED $fail\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
