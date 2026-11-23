<?php
declare(strict_types=1);

namespace App\Domain\Recovery;

/**
 * An explained recovery SIGNAL, not a score. It counts plain flags from things you can see (HRV and resting HR against your own baseline, a short
 * night or a week of sleep debt, how you said you feel, a sharp rise in load) and lists every reason. 0 flags = green, 1 = amber, 2+ = red. There is
 * no validated single "recovery number", so this does not pretend to be one. It suggests; it never changes a calorie target. Not medical advice. Pure PHP.
 */
final class RecoverySignal
{
    public const LOAD_FLAG_RATIO = 1.4;

    /**
     * @param array<string,mixed> $hrv Baseline::compare result
     * @param array<string,mixed> $rhr Baseline::compare result
     * @param array<string,mixed> $sleep SleepStats::compute result
     * @param ?array{sleep:int,soreness:int,energy:int} $readiness today's check-in
     * @param ?float $loadRatio this week's load over your usual (null = unknown)
     * @return array{level:string,headline:string,flags:list<string>,goods:list<string>,suggestion:string,flagCount:int}
     */
    public static function evaluate(array $hrv, array $rhr, array $sleep, ?array $readiness, ?float $loadRatio, bool $deload = false): array
    {
        $flags = $goods = [];
        $known = 0;

        if (($hrv['status'] ?? 'unknown') !== 'unknown') {
            $known++;
            if ($hrv['status'] === 'below') {
                $flags[] = sprintf('HRV is %d%% below your usual (7-day average against your 60-day baseline).', abs((int) round($hrv['pct'])));
            } else {
                $goods[] = $hrv['status'] === 'above' ? 'HRV is above your usual range.' : 'HRV is within your usual range.';
            }
        }
        if (($rhr['status'] ?? 'unknown') !== 'unknown') {
            $known++;
            if ($rhr['status'] === 'above') {
                $flags[] = sprintf('Resting heart rate is about %d bpm above your usual.', max(1, (int) round($rhr['recent'] - $rhr['baseline'])));
            } else {
                $goods[] = 'Resting heart rate is within your usual range.';
            }
        }
        if ($sleep['lastMin'] !== null || $sleep['nights7'] > 0) {
            $known++;
            if ($sleep['short']) {
                $flags[] = sprintf('Last night was %s, %d%% of your %s target.', self::hm((int) $sleep['lastMin']), (int) $sleep['performancePct'], self::hm((int) $sleep['targetMin']));
            } elseif ($sleep['lastMin'] !== null) {
                $goods[] = sprintf('Last night was %s, %d%% of your target.', self::hm((int) $sleep['lastMin']), (int) $sleep['performancePct']);
            }
            if ($sleep['debtFlag']) {
                $flags[] = sprintf('You are about %s short of your sleep target over the last %d nights.', self::hm((int) $sleep['debt7Min']), (int) $sleep['nights7']);
            }
        }
        if ($readiness !== null) {
            $known++;
            if ($readiness['sleep'] <= 2 || $readiness['soreness'] >= 4 || $readiness['energy'] <= 2) {
                $flags[] = 'Your own check-in this morning said sleep, soreness or energy was rough.';
            } else {
                $goods[] = 'Your own check-in looks fine.';
            }
        }
        if ($loadRatio !== null && ! $deload) {
            if ($loadRatio >= self::LOAD_FLAG_RATIO) {
                $flags[] = sprintf('This week\'s training load is %.1fx your usual.', $loadRatio);
            }
        }

        if ($known === 0) {
            return ['level' => 'unknown', 'headline' => 'Not enough data yet', 'flags' => [], 'goods' => [], 'flagCount' => 0,
                'suggestion' => 'Once your watch has synced sleep, HRV and resting heart rate for a couple of weeks, this will compare you with your own normal.'];
        }
        $n = count($flags);
        $level = $n === 0 ? 'green' : ($n === 1 ? 'amber' : 'red');
        $headline = ['green' => 'Looks recovered', 'amber' => 'One thing to watch', 'red' => 'Several signs of fatigue'][$level];
        $suggestion = [
            'green' => 'Nothing stands out. Train as planned.',
            'amber' => 'Train as planned, but if it feels heavy, stopping a set or two short is reasonable.',
            'red' => 'Consider an easier session, or an extra rest day if your week allows. Your call. If you feel unwell, rest and see a GP.',
        ][$level];
        if ($deload) {
            $suggestion = 'It is a deload week, so lower fatigue is the point. ' . $suggestion;
        }

        return ['level' => $level, 'headline' => $headline, 'flags' => $flags, 'goods' => $goods, 'suggestion' => $suggestion, 'flagCount' => $n];
    }

    private static function hm(int $min): string
    {
        return sprintf('%d h %02d min', intdiv($min, 60), $min % 60);
    }
}
