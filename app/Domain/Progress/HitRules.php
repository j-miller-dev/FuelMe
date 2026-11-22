<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Did a finished day land on its targets? Pure. The tolerances are the user's to change (Targets screen); the defaults are a
 * reasonable everyday standard, not a scientific threshold. A day with too little logged is "not logged", never a miss.
 */
final class HitRules
{
    /** kcal and carbs/fat are two-sided (over or under both miss); protein only has a floor, so going over it is fine. */
    public const DEFAULTS = ['kcal_pct' => 5.0, 'protein_min_pct' => 95.0, 'carbs_pct' => 10.0, 'fat_pct' => 10.0];

    /**
     * Fill gaps and clamp to sensible bounds so a typo cannot make every day a hit (or a miss).
     *
     * @param array<string,mixed>|null $rules
     * @return array{kcal_pct:float,protein_min_pct:float,carbs_pct:float,fat_pct:float}
     */
    public static function normalise(?array $rules): array
    {
        $r = array_merge(self::DEFAULTS, array_intersect_key($rules ?? [], self::DEFAULTS));

        return [
            'kcal_pct' => self::clamp((float) $r['kcal_pct'], 1, 30),
            'protein_min_pct' => self::clamp((float) $r['protein_min_pct'], 50, 100),
            'carbs_pct' => self::clamp((float) $r['carbs_pct'], 1, 50),
            'fat_pct' => self::clamp((float) $r['fat_pct'], 1, 50),
        ];
    }

    /**
     * @param array{kcal:float,protein:float,carbs:float,fat:float} $eaten
     * @param array{kcal:float,protein:float,carbs:float,fat:float} $target
     * @param array<string,mixed>|null $rules
     * @return array{logged:bool,hits:?array{kcal:bool,protein:bool,carbs:bool,fat:bool}}
     */
    public static function evaluate(array $eaten, array $target, ?array $rules = null): array
    {
        if ($target['kcal'] <= 0 || $eaten['kcal'] < Adherence::LOGGED_AT * $target['kcal']) {
            return ['logged' => false, 'hits' => null];
        }
        $r = self::normalise($rules);
        $within = fn (float $got, float $want, float $pct): bool => $want <= 0 ? $got <= 0 : abs($got - $want) <= $want * $pct / 100;

        return ['logged' => true, 'hits' => [
            'kcal' => $within($eaten['kcal'], $target['kcal'], $r['kcal_pct']),
            'protein' => $target['protein'] <= 0 || $eaten['protein'] >= $target['protein'] * $r['protein_min_pct'] / 100,
            'carbs' => $within($eaten['carbs'], $target['carbs'], $r['carbs_pct']),
            'fat' => $within($eaten['fat'], $target['fat'], $r['fat_pct']),
        ]];
    }

    /**
     * "N of M logged days had calories and protein on target". Carbs and fat show as dots but are not in the headline, so one
     * missed side macro does not turn a good day into a failed one.
     *
     * @param list<array{logged:bool,hits:?array{kcal:bool,protein:bool,carbs:bool,fat:bool}}> $days
     * @return array{logged:int,onTarget:int,total:int}
     */
    public static function summary(array $days): array
    {
        $logged = array_filter($days, fn ($d) => $d['logged']);

        return [
            'logged' => count($logged),
            'onTarget' => count(array_filter($logged, fn ($d) => $d['hits']['kcal'] && $d['hits']['protein'])),
            'total' => count($days),
        ];
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }
}
