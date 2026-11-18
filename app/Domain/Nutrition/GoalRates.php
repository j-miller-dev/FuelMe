<?php
declare(strict_types=1);

namespace App\Domain\Nutrition;

/**
 * Default goal rates, cap checks and the "no cut suggestions when already lean" guardrail (BLUEPRINT 9.3, 9.7).
 * Caps come from GoalPlanner so there is one source of truth. Pure PHP: no framework imports.
 *
 * The lean-threshold numbers below (BMI 20, body fat 10%) are CONSERVATIVE HEURISTICS chosen for this app,
 * not clinical cut-offs. They exist so the app never proposes a deficit to someone already very lean.
 */
final class GoalRates
{
    /** Bulk defaults, %BW/week (BLUEPRINT 9.3: novice ~0.5, intermediate 0.25-0.35, advanced 0.1-0.25). */
    public const BULK_DEFAULT = ['novice' => 0.5, 'intermediate' => 0.3, 'advanced' => 0.2];
    /** Cut default, %BW/week (9.3: 0.5 when lean). */
    public const CUT_DEFAULT = 0.5;

    public const MIN_CUT_BMI = 20.0;
    public const MIN_CUT_BODY_FAT_PCT = 10.0;

    public static function experience(string $trainingAge): string
    {
        return match ($trainingAge) {
            'lt1' => 'novice',
            'gt3' => 'advanced',
            default => 'intermediate',
        };
    }

    /** Signed rate: negative = lose. */
    public static function default(string $phase, string $experience): float
    {
        return match ($phase) {
            'cut' => -self::CUT_DEFAULT,
            'bulk' => self::BULK_DEFAULT[$experience] ?? self::BULK_DEFAULT['intermediate'],
            default => 0.0,
        };
    }

    /** @return ?string a plain-language reason if the rate is not allowed, otherwise null */
    public static function check(string $phase, string $experience, float $signedRate): ?string
    {
        switch ($phase) {
            case 'maintain':
                return abs($signedRate) > 1e-9 ? 'Maintain means no planned weight change (0 %/week).' : null;
            case 'bulk':
                $cap = GoalPlanner::BULK_CAP[$experience] ?? GoalPlanner::BULK_CAP['intermediate'];
                if ($signedRate <= 0) {
                    return 'A bulk needs a rate above 0 %/week.';
                }
                return $signedRate > $cap + 1e-9
                    ? sprintf('%.2f %%/week is above the %.2f %%/week ceiling for your training experience; faster gain is mostly fat.', $signedRate, $cap)
                    : null;
            case 'cut':
                if ($signedRate >= 0) {
                    return 'A cut needs a rate below 0 %/week.';
                }
                return abs($signedRate) > GoalPlanner::CUT_CAP + 1e-9
                    ? sprintf('%.2f %%/week is above the %.1f %%/week safety cap for cutting.', abs($signedRate), GoalPlanner::CUT_CAP)
                    : null;
        }
        return 'Unknown goal phase.';
    }

    /** @return ?string a plain-language reason a cut should not be offered, otherwise null */
    public static function cutBlockedReason(float $kg, float $cm, ?float $bodyFatPct): ?string
    {
        if ($bodyFatPct !== null && $bodyFatPct > 0.0) {
            return $bodyFatPct < self::MIN_CUT_BODY_FAT_PCT
                ? sprintf('At about %.0f%% body fat, a calorie deficit is not something this app will suggest. Maintaining or a lean bulk is the safer plan.', $bodyFatPct)
                : null;
        }
        $bmi = $kg / (($cm / 100) ** 2);
        return $bmi < self::MIN_CUT_BMI
            ? sprintf('With a BMI of %.1f and no body fat measurement, this app will not suggest a calorie deficit. Add a body fat estimate if you are muscular, or choose maintain or bulk.', $bmi)
            : null;
    }
}
