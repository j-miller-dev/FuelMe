<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * Fluid guidance around training. Every figure here has a published source, and none is a prescription: sweat rates differ a lot
 * between people, so the app also offers the weigh-before-and-after check. Pure PHP.
 *
 * - Daily fluids: NHMRC (Nutrient Reference Values, water) Adequate Intake for FLUIDS (all drinks), men 2.6 L, women 2.1 L.
 * - Before: ACSM 2007 position stand: slowly drink about 5-7 mL/kg at least 4 h before; if urine is dark, about 3-5 mL/kg about 2 h before.
 * - Sodium: ACSM/AIS mention 0.5-0.7 g sodium per litre for sessions over about an hour; the AIS notes there is no consensus on its value.
 * - After: replace 125-150% of the weight lost (Sports Dietitians Australia); 1 kg lost is about 1 L of fluid.
 */
final class Hydration
{
    public const DAILY_FLUID_ML = ['male' => 2600, 'female' => 2100];

    public static function dailyFluidMl(string $sex): int
    {
        return self::DAILY_FLUID_ML[$sex] ?? self::DAILY_FLUID_ML['male'];
    }

    /** @return array{lowMl:int,highMl:int} rounded to 50 mL */
    public static function preMl(float $kg, float $lowPerKg, float $highPerKg): array
    {
        return ['lowMl' => (int) (round($kg * $lowPerKg / 50) * 50), 'highMl' => (int) (round($kg * $highPerKg / 50) * 50)];
    }

    /** Sodium only matters for long or very sweaty sessions. Always says so plainly. */
    public static function sodiumNote(int $minutes): string
    {
        return $minutes >= 60
            ? 'For a session this long you can add a little sodium (about 0.5 to 0.7 g per litre of drink). Experts disagree on how much it helps, so treat it as optional.'
            : 'A session this short does not need electrolytes. Water is enough, and the salt in your meal counts.';
    }

    /** @return array{lowL:float,highL:float} fluid to drink over the hours after, from the weight lost (kg) */
    public static function rehydrate(float $lossKg): array
    {
        $loss = max(0.0, $lossKg);

        return ['lowL' => round($loss * 1.25, 1), 'highL' => round($loss * 1.5, 1)];
    }
}
