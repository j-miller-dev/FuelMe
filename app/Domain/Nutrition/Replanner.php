<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * Replan the REST of the week when today's training is not what the template said (BLUEPRINT 9.6). Days already gone keep the targets
 * they had; the carbs that are still to be eaten this week are re-spread over today and the days after it, so the weekly total is
 * conserved. Protein and fat never move. Pure.
 */
final class Replanner
{
    /**
     * @param array<string,array> $base the plan for the TEMPLATE week (MacroPlanner days)
     * @param array<string,array> $eff the plan for the EFFECTIVE week (template, with logged/changed sessions applied)
     * @param list<string> $pastKeys day keys before today (mon..sun order)
     * @return array{days:array<string,array>, warnings:list<array>}
     */
    public static function remaining(array $base, array $eff, array $pastKeys, float $kg, float $ffm): array
    {
        $keys = MacroPlanner::DAYS;
        $weekCarbs = array_sum(array_map(fn ($d) => (float) $d['carbs'], $base));
        $pastCarbs = array_sum(array_map(fn ($k) => (float) $base[$k]['carbs'], $pastKeys));
        $left = array_values(array_diff($keys, $pastKeys));
        $budget = max(0.0, $weekCarbs - $pastCarbs);

        $w = $min = $max = [];
        foreach ($left as $k) {
            [$lo, $hi] = MacroPlanner::CARB_RANGE[$eff[$k]['class']];
            $w[$k] = max(1.0, (float) $eff[$k]['carbs']);   // the effective plan already says which days want more
            $min[$k] = $lo * $kg;
            $max[$k] = $hi * $kg;
        }
        $warnings = [];
        $alloc = $left ? MacroPlanner::waterFill($w, $min, $max, $budget, $warnings) : [];

        $out = [];
        foreach ($keys as $k) {
            if (in_array($k, $pastKeys, true)) {
                $out[$k] = $base[$k];

                continue;
            }
            $c = round($alloc[$k]);
            $p = (float) $eff[$k]['protein'];
            $f = (float) $eff[$k]['fat'];
            $kcal = 4 * $p + 9 * $f + 4 * $c;
            $out[$k] = [
                'kcal' => round($kcal), 'carbs' => $c, 'carbGPerKg' => round($c / $kg, 1), 'fiber' => round(14.0 * $kcal / 1000),
                'energyAvailability' => round(($kcal - (float) $eff[$k]['netKcal']) / $ffm, 1),
            ] + $eff[$k];
        }

        return ['days' => $out, 'warnings' => $warnings];
    }
}
