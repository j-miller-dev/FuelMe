<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/** Picks 2-3 of YOUR usual foods that would close the average daily gap, so the nudge is something you already eat. Pure. */
final class NudgePicker
{
    /**
     * @param list<array{name:string,grams:float,unit:string,kcal:float,protein:float}> $foods one usual portion each
     * @return list<array{name:string,grams:float,unit:string,kcal:float,protein:float}>
     */
    public static function pick(array $foods, float $gapKcal, float $gapProtein, int $n = 3): array
    {
        if ($gapKcal <= 0) {
            return [];
        }
        $scored = [];
        foreach ($foods as $f) {
            if ($f['kcal'] < 60 || $f['grams'] <= 0) {
                continue;   // a lettuce leaf does not close a 400 kcal gap
            }
            // Reward covering the gap without overshooting it, and protein when protein is short.
            $k = min($f['kcal'], $gapKcal) / $gapKcal;
            $p = $gapProtein > 0 ? min($f['protein'], $gapProtein) / $gapProtein : 0.0;
            $scored[] = ['score' => 0.6 * $k + 0.4 * $p, 'food' => $f];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['food']['name'], $b['food']['name']));
        $out = [];
        $seen = [];
        foreach ($scored as $s) {
            $key = mb_strtolower(explode(',', $s['food']['name'])[0]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s['food'];
            if (count($out) === $n) {
                break;
            }
        }

        return $out;
    }
}
