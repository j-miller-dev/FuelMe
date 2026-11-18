<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * Day-one expenditure prior (BLUEPRINT 9.2), used only to seed the Kalman filter before data exists.
 *
 *   TDEE_prior = RMR x PAL_non-exercise + average weekly exercise kcal / 7
 *
 * RMR: Katch-McArdle when body fat is known, otherwise Mifflin-St Jeor.
 * PAL_non-exercise by job is an ENGINEERING HEURISTIC (low confidence, assumptions registry 9.10): it
 * only has to be roughly right, because the filter takes over as soon as weigh-ins and intake arrive.
 * Pure PHP: no framework imports.
 */
final class DayOnePrior
{
    /** How unsure we are of the prior, kcal/day (BLUEPRINT 9.1: sigma ~350 until data arrives). */
    public const PRIOR_SD = 350.0;

    /** Non-exercise physical activity level by job. Training is added separately, so these exclude it. */
    public const JOB_PAL = [
        'desk'    => 1.30,
        'mixed'   => 1.40,
        'on_feet' => 1.50,
        'heavy'   => 1.65,
    ];

    public const DEFAULT_JOB = 'mixed';

    public static function rmr(float $kg, float $cm, int $age, string $sex, ?float $bodyFatPct = null): array
    {
        if ($bodyFatPct !== null && $bodyFatPct > 0.0 && $bodyFatPct < 60.0) {
            $ffm = $kg * (1 - $bodyFatPct / 100);
            return ['kcal' => 370.0 + 21.6 * $ffm, 'method' => 'katch_mcardle'];
        }
        $kcal = 10 * $kg + 6.25 * $cm - 5 * $age + ($sex === 'male' ? 5 : -161);
        return ['kcal' => $kcal, 'method' => 'mifflin_st_jeor'];
    }

    /**
     * @param array<string,array<int,array{type:string,intensity:string,minutes:int}>> $week planned training by day
     * @return array{tdee:float,sd:float,rmr:float,rmrMethod:string,pal:float,exerciseKcalPerDay:float,palAssumed:bool}
     */
    public static function estimate(
        float $kg,
        float $cm,
        int $age,
        string $sex,
        ?float $bodyFatPct,
        string $job,
        array $week,
    ): array {
        $rmr = self::rmr($kg, $cm, $age, $sex, $bodyFatPct);
        $palAssumed = !isset(self::JOB_PAL[$job]);
        $pal = self::JOB_PAL[$job] ?? self::JOB_PAL[self::DEFAULT_JOB];

        $weeklyNet = 0.0;
        foreach ($week as $sessions) {
            foreach ($sessions as $s) {
                $weeklyNet += ExerciseCalculator::session($s['type'], $s['intensity'], $s['minutes'], $kg)['netKcal'];
            }
        }
        $exercisePerDay = $weeklyNet / 7;

        return [
            'tdee' => round($rmr['kcal'] * $pal + $exercisePerDay),
            'sd' => self::PRIOR_SD,
            'rmr' => round($rmr['kcal']),
            'rmrMethod' => $rmr['method'],
            'pal' => $pal,
            'exerciseKcalPerDay' => round($exercisePerDay),
            'palAssumed' => $palAssumed,
        ];
    }

    public static function ageOn(string $dob, string $today): int
    {
        [$by, $bm, $bd] = array_map('intval', explode('-', $dob));
        [$ty, $tm, $td] = array_map('intval', explode('-', $today));
        $age = $ty - $by;
        if ($tm < $bm || ($tm === $bm && $td < $bd)) {
            $age--;
        }
        return $age;
    }
}
