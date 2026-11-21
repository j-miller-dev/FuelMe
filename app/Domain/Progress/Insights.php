<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Personal insights (BLUEPRINT 9A.6), deliberately plain: rank correlation between two things you logged, shown only with at least
 * MIN_PAIRS pairs, always with the sample size and a "correlation, not proof" label, never causal wording. The strength words are this
 * app's own rule-of-thumb bands, not a statistical test. Also the readiness hook (poor sleep or soreness for several days before a hard day).
 * Pure PHP.
 */
final class Insights
{
    public const MIN_PAIRS = 8;
    public const SMALL_SAMPLE = 20;
    public const HOOK_DAYS = 3;
    public const HARD = ['hit', 'heavy', 'hard', 'vigorous'];

    /** Spearman rank correlation (ties share their average rank). Null if there is nothing to compare. */
    public static function spearman(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n !== count($y) || $n < 2) {
            return null;
        }
        $rx = self::ranks($x);
        $ry = self::ranks($y);
        $mx = array_sum($rx) / $n;
        $my = array_sum($ry) / $n;
        $num = $dx = $dy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($rx[$i] - $mx) * ($ry[$i] - $my);
            $dx += ($rx[$i] - $mx) ** 2;
            $dy += ($ry[$i] - $my) ** 2;
        }

        return $dx == 0.0 || $dy == 0.0 ? null : round($num / sqrt($dx * $dy), 3);
    }

    /** @return list<float> */
    private static function ranks(array $v): array
    {
        $idx = array_keys($v);
        usort($idx, fn ($a, $b) => $v[$a] <=> $v[$b]);
        $r = [];
        $i = 0;
        $n = count($idx);
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $v[$idx[$j + 1]] == $v[$idx[$i]]) {
                $j++;
            }
            for ($k = $i; $k <= $j; $k++) {
                $r[$idx[$k]] = ($i + $j) / 2 + 1;
            }
            $i = $j + 1;
        }
        ksort($r);

        return array_values($r);
    }

    /**
     * @param list<array{0:float,1:float}> $pairs [x, y]
     * @return array{key:string,title:string,n:int,rho:float,strength:string,direction:string,text:string,caveats:list<string>}|null null with fewer than MIN_PAIRS pairs
     */
    public static function pair(string $key, string $title, string $xName, string $yName, array $pairs): ?array
    {
        $n = count($pairs);
        if ($n < self::MIN_PAIRS) {
            return null;
        }
        $rho = self::spearman(array_column($pairs, 0), array_column($pairs, 1));
        if ($rho === null) {
            return null;
        }
        $a = abs($rho);
        $strength = $a < 0.2 ? 'none' : ($a < 0.4 ? 'weak' : ($a < 0.6 ? 'moderate' : 'strong'));
        $dir = $rho > 0 ? 'higher' : 'lower';
        $text = $strength === 'none'
            ? sprintf('No clear link between %s and %s in your %d days.', $xName, $yName, $n)
            : sprintf('On days with higher %s, %s tended to be %s (%s link, %d days).', $xName, $yName, $dir, $strength, $n);

        $caveats = ['Correlation, not proof: other things change on the same days.'];
        if ($n < self::SMALL_SAMPLE) {
            $caveats[] = 'Small sample, so this can easily change as you log more days.';
        }

        return ['key' => $key, 'title' => $title, 'n' => $n, 'rho' => $rho, 'strength' => $strength, 'direction' => $rho > 0 ? 'positive' : 'negative', 'text' => $text, 'caveats' => $caveats];
    }

    public static function poorDay(array $r): bool
    {
        return $r['sleep'] <= 2 || $r['soreness'] >= 4;
    }

    /**
     * @param list<array{date:string,sleep:int,soreness:int,energy:int}> $recent newest first, one per day
     * @param ?array{label:string,intensity:string} $hardSession the next session if it is a hard one
     * @return array{days:int,message:string,options:list<string>}|null
     */
    public static function readinessHook(array $recent, ?array $hardSession): ?array
    {
        if ($hardSession === null || count($recent) < self::HOOK_DAYS) {
            return null;
        }
        $last = array_slice($recent, 0, self::HOOK_DAYS);
        // Needs real consecutive days.
        for ($i = 1; $i < self::HOOK_DAYS; $i++) {
            if ((strtotime($last[$i - 1]['date']) - strtotime($last[$i]['date'])) !== 86400) {
                return null;
            }
        }
        foreach ($last as $r) {
            if (! self::poorDay($r)) {
                return null;
            }
        }

        return ['days' => self::HOOK_DAYS, 'message' => sprintf('Sleep or soreness has been rough for %d days and %s is a hard session. Your call, here are some options.', self::HOOK_DAYS, $hardSession['label']),
            'options' => ['Make it a lighter session', 'Eat the pre-session meal and have a little extra carbs', 'Move it a day']];
    }
}
