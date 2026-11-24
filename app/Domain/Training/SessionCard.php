<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * One card per lifting session: the key numbers and what stood out. Pure PHP, built from your logged sets only.
 *
 * - Working sets exclude warm-ups. Volume = kg x reps of working sets that have BOTH a weight and reps (bodyweight sets count as sets, not volume).
 * - A PR badge means this session beat everything you logged earlier for that lift (heaviest weight, or estimated 1RM). The first time you log a
 *   lift is a baseline, never a PR. Estimated 1RM is an estimate (Strength::bestSet, effort aware).
 * - "vs last time" compares total volume with your previous session of the same title. A description, not a score: a lighter day can be the plan.
 */
final class SessionCard
{
    /**
     * @param list<array<string,mixed>> $sessions oldest first, each with date, title, minutes, exercises[id,title,muscle,sets[type,kg,reps,rpe]], deload
     * @return list<array<string,mixed>> same order as the input
     */
    public static function buildAll(array $sessions): array
    {
        $heaviest = $e1 = $lastVol = [];
        $cards = [];
        foreach ($sessions as $s) {
            $cards[] = self::one($s, $heaviest, $e1, $lastVol);
        }

        return $cards;
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,float> $heaviest running best weight per exercise id (updated)
     * @param array<string,float> $e1 running best estimated 1RM per exercise id (updated)
     * @param array<string,float> $lastVol last volume per session title (updated)
     * @return array<string,mixed>
     */
    private static function one(array $s, array &$heaviest, array &$e1, array &$lastVol): array
    {
        $rows = [];
        $allSets = [];
        $vol = 0.0;
        $working = $reps = $bw = $prs = 0;
        $muscles = [];
        $top = null;
        // merge repeated entries of one exercise inside a session (Hevy can list it twice)
        $merged = [];
        foreach ($s['exercises'] as $ex) {
            $id = (string) $ex['id'];
            isset($merged[$id]) ? $merged[$id]['sets'] = array_merge($merged[$id]['sets'], $ex['sets']) : $merged[$id] = $ex;
        }
        foreach ($merged as $id => $ex) {
            $sets = array_values(array_filter($ex['sets'], fn ($x) => ($x['type'] ?? 'normal') !== 'warmup'));
            array_push($allSets, ...$ex['sets']);
            if (! $sets) {
                continue;
            }
            $usable = array_values(array_filter($sets, fn ($x) => isset($x['kg'], $x['reps']) && (float) $x['kg'] > 0 && (int) $x['reps'] >= 1));
            $v = 0.0;
            foreach ($usable as $x) {
                $v += (float) $x['kg'] * (int) $x['reps'];
            }
            foreach ($sets as $x) {
                $reps += (int) ($x['reps'] ?? 0);
            }
            $bw += count($sets) - count($usable);
            $working += count($sets);
            $vol += $v;
            if (! empty($ex['muscle'])) {
                $muscles[$ex['muscle']] = ($muscles[$ex['muscle']] ?? 0) + count($sets);
            }

            $best = Strength::bestSet($usable);
            $pr = [];
            $heavy = null;
            if ($usable) {
                $maxKg = max(array_map(fn ($x) => (float) $x['kg'], $usable));
                $heavy = ['kg' => $maxKg, 'reps' => max(array_map(fn ($x) => (int) $x['reps'], array_filter($usable, fn ($x) => (float) $x['kg'] === $maxKg)))];
                if (isset($heaviest[$id])) {   // an earlier session of this lift exists, so beating it is a real PR
                    $maxKg > $heaviest[$id] && $pr[] = 'heaviest';
                    $best && $best['e1rm'] > ($e1[$id] ?? INF) && $pr[] = 'e1rm';
                }
                $heaviest[$id] = max($heaviest[$id] ?? 0.0, $maxKg);
                $best && $e1[$id] = max($e1[$id] ?? 0.0, $best['e1rm']);
            }
            $prs += count($pr) > 0 ? 1 : 0;
            if ($best && ($top === null || $best['e1rm'] > $top['e1rm'])) {
                $top = ['exercise' => $ex['title'], 'kg' => $best['kg'], 'reps' => $best['reps'], 'e1rm' => $best['e1rm']];
            }
            $rows[] = ['id' => (string) $id, 'title' => $ex['title'], 'muscle' => $ex['muscle'] ?? null, 'sets' => count($sets), 'volume' => round($v, 1), 'best' => $best ? ['kg' => $best['kg'], 'reps' => $best['reps']] : null,
                'heaviest' => $heavy, 'e1rm' => $best ? round($best['e1rm'], 1) : null, 'pr' => $pr, 'detail' => array_map(fn ($x) => ['kg' => isset($x['kg']) ? (float) $x['kg'] : null, 'reps' => isset($x['reps']) ? (int) $x['reps'] : null, 'type' => $x['type'] ?? 'normal'], $sets)];
        }

        arsort($muscles);
        $mTotal = array_sum($muscles) ?: 1;
        $mus = [];
        foreach (array_slice($muscles, 0, 5, true) as $m => $n) {
            $mus[] = ['muscle' => (string) $m, 'sets' => $n, 'pct' => (int) round($n / $mTotal * 100)];
        }

        $title = (string) $s['title'];
        $vsLast = null;
        if (isset($lastVol[$title]) && $lastVol[$title] > 0 && $vol > 0) {
            $vsLast = ['pct' => (int) round(($vol / $lastVol[$title] - 1) * 100), 'last_kg' => (int) round($lastVol[$title])];
        }
        $vol > 0 && $lastVol[$title] = $vol;

        return [
            'id' => $s['id'] ?? null, 'date' => (string) $s['date'], 'title' => $title, 'minutes' => $s['minutes'] ?? null, 'deload' => (bool) ($s['deload'] ?? false),
            'exercises' => count($rows), 'sets' => $working, 'reps' => $reps, 'volume_kg' => (int) round($vol), 'bodyweight_sets' => $bw,
            'top_set' => $top === null ? null : ['exercise' => $top['exercise'], 'kg' => $top['kg'], 'reps' => $top['reps']],
            'pr_count' => $prs, 'muscles' => $mus, 'effort' => Strength::effort($allSets), 'vs_last' => $vsLast, 'rows' => $rows,
        ];
    }

    /**
     * @param list<array<string,mixed>> $cards newest first
     * @return list<array{month:string,sessions:int,sets:int,volume_kg:int,minutes:int,prs:int,cards:list<array<string,mixed>>}>
     */
    public static function months(array $cards): array
    {
        $by = [];
        foreach ($cards as $c) {
            $m = substr((string) $c['date'], 0, 7);
            $by[$m] ??= ['month' => $m, 'sessions' => 0, 'sets' => 0, 'volume_kg' => 0, 'minutes' => 0, 'prs' => 0, 'cards' => []];
            $by[$m]['sessions']++;
            $by[$m]['sets'] += $c['sets'];
            $by[$m]['volume_kg'] += $c['volume_kg'];
            $by[$m]['minutes'] += (int) ($c['minutes'] ?? 0);
            $by[$m]['prs'] += $c['pr_count'];
            $by[$m]['cards'][] = $c;
        }
        krsort($by);

        return array_values($by);
    }
}
