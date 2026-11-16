<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * MET-based exercise energy using the 2024 Compendium of Physical Activities.
 * NET kcal = (MET - 1) * kg * hours  (the "-1" removes resting metabolism, which the
 * TDEE already counts - avoids double counting). Treat results as +/-25%.
 */
final class ExerciseCalculator
{
    /** type => [label, intensities => [key => [met, source, glycolytic]]] */
    public const CATALOGUE = [
        'bjj' => ['label' => 'Brazilian jiu-jitsu', 'intensities' => [
            'light'    => ['met' => 5.3,  'src' => 'Compendium 15425 martial arts, slower pace/novice (drilling, technique)', 'glycolytic' => false],
            'standard' => ['met' => 7.5,  'src' => 'ABS 1721 Judo/Jujitsu (AU Dept of Health coding) - class with some live rolling', 'glycolytic' => true],
            'hard'     => ['met' => 10.3, 'src' => 'Compendium 15430 martial arts, moderate pace (hard sparring/comp)', 'glycolytic' => true],
        ]],
        'lifting' => ['label' => 'Weight training', 'intensities' => [
            'light'    => ['met' => 3.5, 'src' => 'Compendium 02054 multiple exercises 8-15 reps', 'glycolytic' => false],
            'hit'      => ['met' => 3.5, 'src' => 'Compendium 02054 (closest code). Mentzer-style HIT = few sets to failure with long rests, so the session-average MET is low. ESTIMATE.', 'glycolytic' => false],
            'standard' => ['met' => 5.0, 'src' => 'Compendium 02052 squats/deadlift slow or explosive', 'glycolytic' => false],
            'heavy'    => ['met' => 6.0, 'src' => 'Compendium 02050 resistance, vigorous effort', 'glycolytic' => false],
        ]],
        'hiit' => ['label' => 'HIIT', 'intensities' => [
            'moderate' => ['met' => 7.0,  'src' => 'Compendium 02210 HIIT moderate effort', 'glycolytic' => true],
            'vigorous' => ['met' => 11.0, 'src' => 'Compendium 02214 HIIT burpees/Tabata vigorous', 'glycolytic' => true],
        ]],
        'norwegian_4x4' => ['label' => 'Norwegian 4x4', 'intensities' => [
            'standard' => ['met' => 8.8, 'src' => 'Compendium 01305 cycling HIIT (closest code - ESTIMATE)', 'glycolytic' => true],
        ]],
    ];

    /** @return array{met:float,netKcal:float,low:float,high:float,glycolytic:bool,src:string} */
    public static function session(string $type, string $intensity, int $minutes, float $kg, ?float $customMet = null): array
    {
        $def = self::CATALOGUE[$type]['intensities'][$intensity] ?? null;
        if ($customMet === null && $def === null) {
            throw new \InvalidArgumentException("Unknown activity $type/$intensity");
        }
        $met = $customMet ?? $def['met'];
        $net = max(0.0, ($met - 1.0) * $kg * $minutes / 60.0);
        return [
            'met' => $met,
            'netKcal' => round($net),
            'low' => round($net * 0.75),
            'high' => round($net * 1.25),
            'glycolytic' => $def['glycolytic'] ?? ($met >= 6.0),
            'src' => $def['src'] ?? 'custom MET',
        ];
    }
}
