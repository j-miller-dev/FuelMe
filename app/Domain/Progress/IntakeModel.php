<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * What a day's food log is WORTH to the expenditure filter (BLUEPRINT 9A.1). A messy day must never become "no data": an exact day
 * is trusted, a rough day is used but with a wider error, and only a day with almost nothing logged counts as missing. Pure.
 */
final class IntakeModel
{
    public const BASE_SD = 150.0;        // the filter's default logging error
    public const ROUGH_DAY_SD = 350.0;   // "roughly on target": the whole day is a guess (blueprint says 300-400)
    public const ROUGH_MEAL_ERROR = 0.30; // a rough meal is out by about a third
    public const MAX_SD = 400.0;

    /**
     * @param float $loggedKcal everything logged that day
     * @param float $roughKcal  the part of it you flagged as a rough estimate
     * @param float|null $estimateKcal a whole-day estimate ("roughly on target"), or null
     * @return array{state:'exact'|'rough'|'missing',kcal:?float,sd:?float}
     */
    public static function day(float $loggedKcal, float $roughKcal, ?float $estimateKcal, float $target): array
    {
        // A whole-day estimate stands in for the day, unless you went on to log more than it said (then the real log wins).
        if ($estimateKcal !== null && $estimateKcal > $loggedKcal) {
            return ['state' => 'rough', 'kcal' => $estimateKcal, 'sd' => self::ROUGH_DAY_SD];
        }
        if ($target <= 0 || $loggedKcal < Adherence::LOGGED_AT * $target) {
            return ['state' => 'missing', 'kcal' => null, 'sd' => null];
        }
        if ($roughKcal <= 0) {
            return ['state' => 'exact', 'kcal' => $loggedKcal, 'sd' => null];
        }
        $sd = min(self::MAX_SD, sqrt(self::BASE_SD ** 2 + (self::ROUGH_MEAL_ERROR * $roughKcal) ** 2));

        return ['state' => $roughKcal > 0.5 * $loggedKcal ? 'rough' : 'exact', 'kcal' => $loggedKcal, 'sd' => $sd];
    }

    /**
     * The weekly consistency score that replaces a streak: days that COUNTED (logged or good enough) out of the window, plus how many
     * of the exactly-logged days landed within +/-15% of target. A missed day is a neutral gap, never a failure.
     *
     * @param list<array{state:string,kcal:?float,target:float}> $days
     * @return array{counted:int,total:int,exact:int,within15:int}
     */
    public static function consistency(array $days): array
    {
        $counted = array_filter($days, fn ($d) => $d['state'] !== 'missing');
        $exact = array_filter($days, fn ($d) => $d['state'] === 'exact' && $d['kcal'] !== null && $d['target'] > 0);

        return [
            'counted' => count($counted), 'total' => count($days), 'exact' => count($exact),
            'within15' => count(array_filter($exact, fn ($d) => abs($d['kcal'] - $d['target']) <= 0.15 * $d['target'])),
        ];
    }
}
