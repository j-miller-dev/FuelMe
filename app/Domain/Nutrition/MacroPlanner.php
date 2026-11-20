<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * Turns a weekly calorie target into a 7-day macro plan.
 *
 * Priority order: safety floor -> protein -> fat floor/target -> carbs (remainder),
 * then carbs are PERIODISED across days inside evidence-based g/kg ranges while the
 * weekly calorie total is conserved. Exercise calories are NOT added to the budget
 * (the adaptive TDEE already contains typical training) - they only shape distribution.
 */
final class MacroPlanner
{
    /** Burke 2011 / ACSM-AND-DC 2016 daily carbohydrate ranges, g/kg/day. */
    public const CARB_RANGE = [
        'rest'      => [3.0, 5.0],
        'light'     => [3.0, 5.0],
        'moderate'  => [5.0, 7.0],
        'high'      => [6.0, 10.0],
        'very_high' => [8.0, 12.0],
    ];
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array{
     *   weightKg: float, sex?: string, bodyFatPct?: ?float, phase: string, kcalTarget: float,
     *   week: array<string,array<int,array{type:string,intensity:string,minutes:int}>>,
     *   proteinGPerKg?: ?float, carbMode?: string, carbCapG?: ?float, periodisation?: float
     * } $p
     */
    public static function plan(array $p): array
    {
        $kg = (float) $p['weightKg'];
        $phase = $p['phase'];
        $bf = $p['bodyFatPct'] ?? null;
        $assumedBf = $bf === null;
        $ffm = $kg * (1 - ($bf ?? 18.0) / 100);
        $warnings = [];
        if ($assumedBf) {
            $warnings[] = ['level' => 'info', 'code' => 'bf_assumed', 'msg' => 'Body fat not set: assuming 18% for fat-free mass. Add a measurement for better protein and energy-availability maths.'];
        }

        // 1. Per-day training load
        $days = [];
        $netByDay = [];
        foreach (self::DAYS as $d) {
            $sessions = [];
            $eff = 0.0;
            $net = 0.0;
            foreach ($p['week'][$d] ?? [] as $s) {
                $r = ExerciseCalculator::session($s['type'], $s['intensity'], $s['minutes'], $kg, isset($s['customMet']) ? (float) $s['customMet'] : null);
                $w = $r['met'] >= 6.0 ? 1.0 : ($r['met'] >= 4.0 ? 0.5 : 0.25);
                $eff += $s['minutes'] * $w;
                $net += $r['netKcal'];
                $sessions[] = $s + ['netKcal' => $r['netKcal'], 'met' => $r['met']];
            }
            $class = match (true) {
                $eff <= 0 => 'rest', $eff < 45 => 'light', $eff < 90 => 'moderate',
                $eff < 180 => 'high', default => 'very_high',
            };
            $days[$d] = ['class' => $class, 'sessions' => $sessions, 'netKcal' => round($net)];
            $netByDay[$d] = $net;
        }
        $avgEEE = array_sum($netByDay) / 7;

        // 2. Safety floor from energy availability (>= 30 kcal/kg FFM/day, IOC RED-S)
        $kcal = (float) $p['kcalTarget'];
        $floor = 30.0 * $ffm + $avgEEE;
        if ($kcal < $floor) {
            $warnings[] = ['level' => 'critical', 'code' => 'energy_floor', 'msg' => sprintf('Target %d kcal is below the safe floor (%d kcal, energy availability <30 kcal/kg FFM). Raised to the floor.', $kcal, $floor)];
            $kcal = $floor;
        }

        // 3. Protein (ISSN 2017: 1.4-2.0 g/kg; 2.3-3.1 g/kg FFM when lean + hypocaloric)
        $protG = isset($p['proteinGPerKg']) ? $p['proteinGPerKg'] * $kg : match ($phase) {
            'cut' => max(2.0 * $kg, 2.3 * $ffm),
            'maintain' => 1.8 * $kg,
            default => 1.8 * $kg, // bulk (ISSN/Iraki 1.6-2.2)
        };
        $protG = min($protG, 0.35 * $kcal / 4);
        $protG = round($protG);

        // 4. Fat: floor 0.6 g/kg (literature minimum 0.5), target share by phase, ceiling 1.5 g/kg
        $fatFloor = max(0.6 * $kg, 0.15 * $kcal / 9);
        $fatShare = match ($phase) { 'cut' => 0.25, 'maintain' => 0.27, default => 0.25 };
        $fatG = max($fatFloor, $fatShare * $kcal / 9);
        $fatG = round(min($fatG, 1.5 * $kg));

        // 5. Carbs = remainder (or capped / keto)
        $mode = $p['carbMode'] ?? 'flexible';
        $weekCarbs = 7 * ($kcal - 4 * $protG - 9 * $fatG) / 4;
        if ($mode === 'flexible' && $weekCarbs < 7 * 3.0 * $kg && $weekCarbs >= 0) {
            $warnings[] = ['level' => 'warn', 'code' => 'low_carb_budget', 'msg' => 'Carbs fall below 3 g/kg after protein and fat. Consider a higher calorie target or a slower rate.'];
        }
        if ($weekCarbs < 0) {
            $warnings[] = ['level' => 'critical', 'code' => 'infeasible', 'msg' => 'Calories cannot cover protein + minimum fat. Raise the calorie target.'];
            $weekCarbs = 0;
        }
        if ($mode === 'keto') {
            $carbsKeto = 30.0;
            $fatG = round(($kcal - 4 * $protG - 4 * $carbsKeto) / 9);
            $weekCarbs = 7 * $carbsKeto;
            $warnings[] = ['level' => 'warn', 'code' => 'keto_training', 'msg' => 'Keto mode: glycolytic sessions (BJJ rolling, HIIT) will be under-fuelled. Consider a targeted carb dose pre-session.'];
        } elseif ($mode === 'cap' && isset($p['carbCapG'])) {
            $cap = min($weekCarbs / 7, (float) $p['carbCapG']);
            $fatG = min(round(1.5 * $kg), round(($kcal - 4 * $protG - 4 * $cap) / 9));
            $weekCarbs = 7 * $cap;
        }

        // 6. Periodise carbs across days (water-filling inside g/kg ranges)
        $k = (float) ($p['periodisation'] ?? 0.6);
        $mids = [];
        $mins = [];
        $maxs = [];
        foreach (self::DAYS as $d) {
            [$lo, $hi] = self::CARB_RANGE[$days[$d]['class']];
            $mids[$d] = ($lo + $hi) / 2 * $kg;
            $mins[$d] = $lo * $kg;
            $maxs[$d] = $hi * $kg;
        }
        $meanMid = array_sum($mids) / 7;
        $weights = [];
        foreach (self::DAYS as $d) {
            $weights[$d] = (1 - $k) + $k * ($mids[$d] / $meanMid);
        }
        $alloc = $mode === 'keto'
            ? array_fill_keys(self::DAYS, $weekCarbs / 7)
            : self::waterFill($weights, $mins, $maxs, $weekCarbs, $warnings);

        // 7. Assemble days
        $fiberPer1000 = 14.0; // IOM adequate-intake heuristic
        $out = [];
        $sumKcal = 0.0;
        foreach (self::DAYS as $d) {
            $c = round($alloc[$d]);
            $dayKcal = 4 * $protG + 9 * $fatG + 4 * $c;
            $ea = ($dayKcal - $days[$d]['netKcal']) / $ffm;
            $out[$d] = $days[$d] + [
                'kcal' => round($dayKcal), 'protein' => $protG, 'fat' => $fatG, 'carbs' => $c,
                'carbGPerKg' => round($c / $kg, 1),
                'fiber' => round($fiberPer1000 * $dayKcal / 1000),
                'energyAvailability' => round($ea, 1),
            ];
            $sumKcal += $dayKcal;
            [$lo] = self::CARB_RANGE[$days[$d]['class']];
            if ($mode === 'flexible' && $c / $kg < $lo - 0.01) {
                $warnings[] = ['level' => 'warn', 'code' => 'underfuelled', 'day' => $d, 'msg' => sprintf('%s: %.1f g/kg carbs is below the %.0f g/kg suggested for a %s day.', strtoupper($d), $c / $kg, $lo, $days[$d]['class'])];
            }
            if ($ea < 30) {
                $warnings[] = ['level' => 'warn', 'code' => 'low_ea_day', 'day' => $d, 'msg' => sprintf('%s: energy availability %.0f kcal/kg FFM is under 30.', strtoupper($d), $ea)];
            }
        }

        // per-meal protein dose (ISSN 0.25 g/kg or 20-40 g; Iraki/Helms 0.4-0.55 g/kg)
        $dose = max(20.0, min(50.0, 0.4 * $kg));
        $meals = (int) max(3, min(6, ceil($protG / $dose)));

        return [
            'days' => $out,
            'weekly' => [
                'kcalAvg' => round($sumKcal / 7), 'kcalTargetUsed' => round($kcal),
                'protein' => $protG, 'proteinGPerKg' => round($protG / $kg, 2),
                'fat' => $fatG, 'fatGPerKg' => round($fatG / $kg, 2),
                'carbsAvg' => round(array_sum($alloc) / 7), 'proteinPerMealG' => round($dose),
                'proteinMeals' => $meals, 'avgExerciseKcal' => round($avgEEE), 'ffmKg' => round($ffm, 1),
            ],
            'warnings' => $warnings,
        ];
    }

    /** Distribute $total across keys proportional to weights, honouring [min,max]; if impossible, degrade gracefully. */
    public static function waterFill(array $w, array $min, array $max, float $total, array &$warnings): array
    {
        $sumMin = array_sum($min);
        $sumMax = array_sum($max);
        if ($total <= $sumMin) {
            $f = $sumMin > 0 ? $total / $sumMin : 0;
            return array_map(fn($m) => $m * $f, $min);
        }
        if ($total >= $sumMax) { // more carbs than any day "needs": spread by weight, no cap
            $sw = array_sum($w);
            return array_map(fn($x) => $total * $x / $sw, $w);
        }
        $alloc = [];
        $free = array_keys($w);
        $remaining = $total;
        for ($iter = 0; $iter < 10 && $free; $iter++) {
            $sw = 0.0;
            foreach ($free as $d) $sw += $w[$d];
            $violated = false;
            $tmp = [];
            foreach ($free as $d) {
                $x = $remaining * $w[$d] / $sw;
                if ($x < $min[$d]) { $alloc[$d] = $min[$d]; $violated = true; }
                elseif ($x > $max[$d]) { $alloc[$d] = $max[$d]; $violated = true; }
                else $tmp[$d] = $x;
            }
            if (!$violated) { foreach ($tmp as $d => $x) $alloc[$d] = $x; break; }
            $free = array_values(array_diff($free, array_keys($alloc)));
            $remaining = $total - array_sum($alloc);
        }
        return $alloc;
    }
}
