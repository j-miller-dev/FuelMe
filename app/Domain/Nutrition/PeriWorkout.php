<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * Pre / during / post session fuelling targets. These are carved OUT of the day's macros
 * (they shape distribution), never added on top.
 * Sources: ACSM/AND/DC 2016 (pre 1-4 g/kg in 1-4 h; post 1.0-1.2 g/kg/h x4h if <8 h to next
 * session; 30-60 g/h during >=60 min), ISSN 2017 (protein 0.25 g/kg or 20-40 g; Helms 0.4-0.5 g/kg
 * around resistance training). Fat/fibre caps are practical GI heuristics, not trial-derived.
 */
final class PeriWorkout
{
    /**
     * @param array{type:string,intensity:string,minutes:int} $session
     * @param ?float $hoursToNextSession null if none within 24 h
     * @param ?array{type:string,intensity:string,minutes:int} $nextSession
     */
    public static function plan(float $kg, array $session, ?float $hoursBeforeMeal = 2.0, ?float $hoursToNextSession = null, ?array $nextSession = null): array
    {
        $r = ExerciseCalculator::session($session['type'], $session['intensity'], $session['minutes'], $kg);
        $demand = $r['glycolytic'] && $r['met'] >= 7.0 ? 1.0 : ($r['met'] >= 5.0 ? 0.75 : 0.5);

        // PRE
        if ($hoursBeforeMeal === null) {
            $pre = ['skip' => true, 'note' => 'Training fasted/very light: acceptable for sessions <60 min at low-moderate intensity; hit the day total afterwards.'];
        } elseif ($hoursBeforeMeal < 1.0) {
            $pre = ['carbsG' => 25, 'proteinG' => 0, 'fatMaxG' => 3, 'fibreMaxG' => 3,
                'note' => 'Under an hour out: small, fast, easily digested carbs only (~25 g).'];
        } else {
            $h = min(4.0, $hoursBeforeMeal);
            $perKg = $h * $demand;                       // ~1 g/kg per hour of lead time, scaled by session demand
            $perKg = max(1.0, min(4.0, $perKg));
            $pre = [
                'carbsG' => (int) round($perKg * $kg),
                'proteinG' => (int) round(0.3 * $kg),
                'fatMaxG' => $h >= 3 ? 15 : ($h >= 2 ? 8 : 3),
                'fibreMaxG' => $h >= 3 ? 10 : 5,
                'note' => sprintf('%.1f g/kg carbs, low fat/fibre, moderate protein.', $perKg),
            ];
        }

        if (isset($pre['carbsG']) && !$r['glycolytic'] && $r['met'] < 5.0) {
            $pre['note'] = 'Low-volume lifting (HIT) needs no carb loading. A normal mixed meal 1-3 h before is enough; protein matters more than carbs here.';
        }

        // DURING
        $during = ($session['minutes'] >= 60 && $r['met'] >= 8.0)
            ? ['carbsGPerHour' => [30, 60], 'note' => 'Hard session >=60 min: 30-60 g/h carbs plus fluids/sodium.']
            : ['carbsGPerHour' => [0, 0], 'note' => 'Water (+electrolytes if sweaty). Carbs optional.'];

        // POST
        $protein = (int) round(max(20.0, min(50.0, 0.4 * $kg)));
        $post = ['proteinG' => $protein, 'withinHours' => 2];
        $urgent = $hoursToNextSession !== null && $hoursToNextSession < 8.0;
        $nextGlyco = $nextSession !== null
            ? ExerciseCalculator::session($nextSession['type'], $nextSession['intensity'], $nextSession['minutes'], $kg)['glycolytic']
            : $r['glycolytic'];
        if ($urgent && $nextGlyco) {
            $post['carbsGPerHour'] = [round(1.0 * $kg), round(1.2 * $kg)];
            $post['forHours'] = 4;
            $post['note'] = 'Next glycolytic session <8 h away: 1.0-1.2 g/kg/h carbs for 4 h (ACSM), with protein.';
        } elseif ($urgent) {
            $post['carbsG'] = (int) round(1.0 * $kg);
            $post['note'] = 'Next session is resistance-only and <8 h away: ~1 g/kg carbs in the first 1-2 h is plenty.';
        } else {
            $post['carbsG'] = (int) round(0.75 * $kg);
            $post['note'] = '>8 h to next session: no special loading. Hit the day\'s carb target; ~0.75 g/kg in the post meal is a sensible default.';
        }

        return ['sessionNetKcal' => $r['netKcal'], 'pre' => $pre, 'during' => $during, 'post' => $post];
    }
}
