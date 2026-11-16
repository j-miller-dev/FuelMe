<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * Joint Kalman filter over [trend weight (kg), expenditure (kcal/day)].
 *
 *   W[t+1] = W[t] + (intake[t] - E[t]) / kcalPerKg      (energy balance)
 *   E[t+1] = E[t] + noise                               (slow random walk)
 *   scale[t] = W[t] + noise                             (noisy observation)
 *
 * Missing weigh-in  -> skip the update (uncertainty grows).
 * Missing intake    -> assume intake == E and add large uncertainty.
 * Water spikes      -> outliers get their measurement noise inflated.
 */
final class ExpenditureFilter
{
    public function __construct(
        private float $kcalPerKg = 7700.0,
        private float $scaleSd = 0.7,          // kg, day-to-day scale noise
        private float $weightProcessSd = 0.05, // kg/day unmodelled drift
        private float $expenditureProcessSd = 20.0, // kcal/day random walk
        private float $intakeSd = 150.0,       // kcal, logging error per day
        private float $missingIntakeSd = 700.0,
        private float $outlierZ = 2.5,
    ) {}

    /**
     * @param array<int,array{date:string,weight:?float,intake:?float}> $days chronological, one row per day
     * @param float $priorTdee   starting estimate (e.g. Mifflin-St Jeor * activity)
     * @param float $priorTdeeSd how unsure we are of that prior
     * @return array<int,array{date:string,trend:float,tdee:float,tdeeSd:float,scale:?float}>
     */
    public function run(array $days, float $startWeight, float $priorTdee, float $priorTdeeSd = 350.0): array
    {
        $k = $this->kcalPerKg;
        $w = $startWeight;
        $e = $priorTdee;
        $p11 = 1.0;
        $p12 = 0.0;
        $p22 = $priorTdeeSd ** 2;
        $out = [];
        $prevIntake = null;

        foreach ($days as $i => $d) {
            if ($i > 0) {
                // PREDICT: yesterday's intake produces this morning's weight.
                if ($prevIntake !== null) {
                    $w += ($prevIntake - $e) / $k;
                    $n11 = $p11 - 2 * $p12 / $k + $p22 / ($k * $k);
                    $n12 = $p12 - $p22 / $k;
                    $p11 = $n11 + $this->weightProcessSd ** 2 + ($this->intakeSd / $k) ** 2;
                    $p12 = $n12;
                } else {
                    $p11 += $this->weightProcessSd ** 2 + ($this->missingIntakeSd / $k) ** 2;
                }
                $p22 += $this->expenditureProcessSd ** 2;
            }

            // UPDATE with the scale reading, if any.
            if ($d['weight'] !== null) {
                $r = $this->scaleSd ** 2;
                $v = $d['weight'] - $w;
                $s = $p11 + $r;
                $z = abs($v) / sqrt($s);
                if ($z > $this->outlierZ) {
                    $r *= ($z / $this->outlierZ) ** 2; // down-weight spikes
                    $s = $p11 + $r;
                }
                $k1 = $p11 / $s;
                $k2 = $p12 / $s;
                $w += $k1 * $v;
                $e += $k2 * $v;
                $p22 -= $k2 * $p12;
                $p11 -= $k1 * $p11;
                $p12 -= $k1 * $p12;
            }

            $out[] = [
                'date' => $d['date'],
                'trend' => $w,
                'tdee' => $e,
                'tdeeSd' => sqrt(max($p22, 0.0)),
                'scale' => $d['weight'],
            ];
            $prevIntake = $d['intake'];
        }

        return $out;
    }
}
