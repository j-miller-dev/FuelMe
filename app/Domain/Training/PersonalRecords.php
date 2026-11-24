<?php

namespace App\Domain\Training;

/**
 * Your best lifts per exercise, from logged sets. Pure PHP, plain arithmetic.
 *
 * - Warm-up sets never count. Sets with no weight (bodyweight, cardio) or no reps are skipped, so a bodyweight lift has no record here rather than a made-up one.
 * - Records: heaviest weight (any reps), best estimated 1RM (Strength::bestSet, effort aware, an ESTIMATE), best reps at each weight you have used,
 *   and best session volume (kg x reps over the working sets of that exercise in one session).
 * - A record is the FIRST time the number was reached; matching it later is not a new record.
 * - "new" = set within `$newDays` days of `$today` AND beat an earlier session. The very first time you log a lift, or the first time at a weight, is a baseline, never called a PR.
 *
 * Session shape is Strength's: ['date', 'exercises' => [['id','title','muscle','secondary','sets' => [...]]]].
 */
final class PersonalRecords
{
    public const NEW_DAYS = 14;

    public const MAX_REP_WEIGHTS = 12;

    /**
     * @param list<array<string,mixed>> $sessions any order
     * @return list<array<string,mixed>> one entry per exercise that has at least one usable set, most recent first
     */
    public static function build(array $sessions, string $today, int $newDays = self::NEW_DAYS): array
    {
        usort($sessions, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $cutoff = date('Y-m-d', strtotime($today . " -{$newDays} days"));

        /** @var array<string,array<string,mixed>> $by */
        $by = [];
        foreach ($sessions as $s) {
            $perEx = [];
            foreach ($s['exercises'] as $ex) {
                $id = (string) $ex['id'];
                $perEx[$id]['ex'] = $ex;
                $perEx[$id]['sets'] = array_merge($perEx[$id]['sets'] ?? [], $ex['sets']);
            }
            foreach ($perEx as $id => $p) {
                $usable = array_values(array_filter($p['sets'], fn ($x) => ($x['type'] ?? 'normal') !== 'warmup' && isset($x['kg'], $x['reps']) && (float) $x['kg'] > 0 && (int) $x['reps'] >= 1));
                if (! $usable) {
                    continue;
                }
                $by[$id] ??= ['id' => $id, 'title' => $p['ex']['title'], 'muscle' => $p['ex']['muscle'] ?? null, 'rows' => []];
                $by[$id]['title'] = $p['ex']['title'];
                $by[$id]['muscle'] = $p['ex']['muscle'] ?? $by[$id]['muscle'];
                $by[$id]['rows'][] = ['date' => $s['date'], 'sets' => $usable];
            }
        }

        $out = [];
        foreach ($by as $e) {
            $out[] = self::one($e, $cutoff);
        }
        usort($out, fn ($a, $b) => strcmp($b['last_date'], $a['last_date']) ?: strcmp($a['title'], $b['title']));

        return $out;
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    private static function one(array $e, string $cutoff): array
    {
        $heaviest = $e1 = $vol = null;
        $repBest = [];   // weight key => record
        $seen = 0;
        foreach ($e['rows'] as $row) {
            $date = $row['date'];
            $fresh = $seen >= 1;   // an earlier session exists, so beating it is a real PR
            $isNew = fn () => $fresh && $date >= $cutoff;

            // heaviest weight; ties on weight go to the set with more reps
            $top = null;
            foreach ($row['sets'] as $x) {
                if ($top === null || (float) $x['kg'] > (float) $top['kg'] || ((float) $x['kg'] === (float) $top['kg'] && (int) $x['reps'] > (int) $top['reps'])) {
                    $top = $x;
                }
            }
            if ($heaviest === null || (float) $top['kg'] > $heaviest['kg']) {
                $heaviest = ['kg' => (float) $top['kg'], 'reps' => (int) $top['reps'], 'date' => $date, 'new' => $isNew()];
            }

            // estimated 1RM (effort aware)
            $b = Strength::bestSet($row['sets']);
            if ($b && ($e1 === null || $b['e1rm'] > $e1['e1rm'])) {
                $e1 = ['e1rm' => $b['e1rm'], 'kg' => $b['kg'], 'reps' => $b['reps'], 'date' => $date, 'new' => $isNew()];
            }

            // session volume
            $v = 0.0;
            foreach ($row['sets'] as $x) {
                $v += (float) $x['kg'] * (int) $x['reps'];
            }
            $v = round($v, 1);
            if ($vol === null || $v > $vol['kg']) {
                $vol = ['kg' => $v, 'sets' => count($row['sets']), 'date' => $date, 'new' => $isNew()];
            }

            // best reps at each weight
            foreach ($row['sets'] as $x) {
                $k = number_format((float) $x['kg'], 2, '.', '');
                if (! isset($repBest[$k])) {
                    $repBest[$k] = ['kg' => (float) $x['kg'], 'reps' => (int) $x['reps'], 'date' => $date, 'new' => false];   // first time at this weight: a baseline, not a PR (the heaviest record covers a new top weight)
                } elseif ((int) $x['reps'] > $repBest[$k]['reps']) {
                    $repBest[$k] = ['kg' => (float) $x['kg'], 'reps' => (int) $x['reps'], 'date' => $date, 'new' => $isNew()];
                }
            }
            $seen++;
        }

        // the weights shown: the heaviest ones you have used, capped
        uasort($repBest, fn ($a, $b) => $b['kg'] <=> $a['kg']);
        $repBest = array_slice(array_values($repBest), 0, self::MAX_REP_WEIGHTS);
        $last = end($e['rows']);

        return [
            'id' => $e['id'], 'title' => $e['title'], 'muscle' => $e['muscle'], 'sessions' => $seen, 'last_date' => $last['date'],
            'heaviest' => $heaviest, 'best_e1rm' => $e1, 'best_volume' => $vol, 'rep_bests' => $repBest,
            'has_new' => (bool) ($heaviest['new'] || ($e1['new'] ?? false) || $vol['new'] || count(array_filter($repBest, fn ($r) => $r['new']))),
        ];
    }
}
