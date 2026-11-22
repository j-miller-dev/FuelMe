<?php

namespace App\Domain\Training;

/**
 * Plain arithmetic on logged lifting sets. Pure PHP. Everything here is an ESTIMATE or a rule of thumb and is labelled so in the UI:
 * - e1RM = Epley (kg x (1 + reps/30)), only for 1-12 reps; heavier-rep sets are too unreliable to use.
 * - Weekly sets per muscle: primary muscle counts 1, a secondary muscle 0.5 (a common rule of thumb, not a measurement).
 * - Stall/progress status needs enough sessions; with too few it says "not enough data" instead of guessing.
 *
 * Session shape: ['date' => 'Y-m-d', 'exercises' => [['id' => string, 'title' => string, 'muscle' => ?string, 'secondary' => list<string>,
 *                 'sets' => [['type' => 'normal'|'warmup'|'failure'|'dropset', 'kg' => ?float, 'reps' => ?int, 'rpe' => ?float]]]]]
 */
final class Strength
{
    public const MAX_REPS = 12;

    public const NEW_BEST = 1.005;      // a session counts as a new best only when it beats the earlier best by more than 0.5%

    public const DOWN_BELOW = 0.95;

    public static function e1rm(?float $kg, ?int $reps): ?float
    {
        if ($kg === null || $reps === null || $kg <= 0 || $reps < 1 || $reps > self::MAX_REPS) {
            return null;
        }

        return round($reps === 1 ? $kg : $kg * (1 + $reps / 30), 1);
    }

    /** @param list<array<string,mixed>> $sets @return array{kg:float,reps:int,e1rm:float}|null the working set with the highest e1RM */
    public static function bestSet(array $sets): ?array
    {
        $best = null;
        foreach ($sets as $s) {
            if (($s['type'] ?? 'normal') === 'warmup') {
                continue;
            }
            $e = self::e1rm(isset($s['kg']) ? (float) $s['kg'] : null, isset($s['reps']) ? (int) $s['reps'] : null);
            if ($e !== null && ($best === null || $e > $best['e1rm'])) {
                $best = ['kg' => (float) $s['kg'], 'reps' => (int) $s['reps'], 'e1rm' => $e];
            }
        }

        return $best;
    }

    /**
     * Best set per session for one exercise, oldest first.
     * @param list<array<string,mixed>> $sessions
     * @return list<array{date:string,kg:float,reps:int,e1rm:float}>
     */
    public static function history(array $sessions, string $exerciseId): array
    {
        usort($sessions, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $out = [];
        foreach ($sessions as $s) {
            $sets = [];
            foreach ($s['exercises'] as $ex) {
                if ((string) $ex['id'] === $exerciseId) {
                    array_push($sets, ...$ex['sets']);
                }
            }
            if ($sets && ($b = self::bestSet($sets))) {
                $out[] = ['date' => $s['date']] + $b;
            }
        }

        return $out;
    }

    /**
     * @param list<array{date:string,e1rm:float}> $h oldest first
     * @return array{status:string,best:?float,latest:?float,sessions:int}
     */
    public static function status(array $h): array
    {
        $n = count($h);
        $best = $n ? max(array_column($h, 'e1rm')) : null;
        $latest = $n ? $h[$n - 1]['e1rm'] : null;
        if ($n < 3) {
            return ['status' => 'not_enough_data', 'best' => $best, 'latest' => $latest, 'sessions' => $n];
        }
        $newBest = false;
        for ($i = max(1, $n - 3); $i < $n; $i++) {
            $prev = max(array_column(array_slice($h, 0, $i), 'e1rm'));
            if ($h[$i]['e1rm'] > $prev * self::NEW_BEST) {
                $newBest = true;
            }
        }
        if ($newBest) {
            $s = 'progressing';
        } elseif ($latest < $best * self::DOWN_BELOW) {
            $s = 'down';
        } else {
            $spanDays = (strtotime($h[$n - 1]['date']) - strtotime($h[$n - 3]['date'])) / 86400;
            $s = $n >= 5 && $spanDays >= 14 ? 'stalled' : 'holding';
        }

        return ['status' => $s, 'best' => $best, 'latest' => $latest, 'sessions' => $n];
    }

    /**
     * What to beat next time, from the last best set. The load step is an ASSUMPTION (smallest plate jump), passed in.
     * @param array{kg:float,reps:int} $last
     * @return list<array{kg:float,reps:int,label:string}>
     */
    public static function beatTheLog(array $last, float $step = 2.5): array
    {
        return [
            ['kg' => $last['kg'], 'reps' => $last['reps'] + 1, 'label' => 'One more rep at the same weight'],
            ['kg' => round($last['kg'] + $step, 2), 'reps' => $last['reps'], 'label' => 'A small load step at the same reps'],
        ];
    }

    /**
     * Working sets per muscle between two dates (inclusive). Secondary muscles count half.
     * @param list<array<string,mixed>> $sessions
     * @return array<string,float>
     */
    public static function weeklySets(array $sessions, string $from, string $to): array
    {
        $out = [];
        foreach ($sessions as $s) {
            if ($s['date'] < $from || $s['date'] > $to) {
                continue;
            }
            foreach ($s['exercises'] as $ex) {
                $n = count(array_filter($ex['sets'], fn ($x) => ($x['type'] ?? 'normal') !== 'warmup'));
                if ($n === 0) {
                    continue;
                }
                if (! empty($ex['muscle'])) {
                    $out[$ex['muscle']] = ($out[$ex['muscle']] ?? 0) + $n;
                }
                foreach ($ex['secondary'] ?? [] as $m) {
                    $out[$m] = ($out[$m] ?? 0) + $n * 0.5;
                }
            }
        }
        arsort($out);

        return $out;
    }

    /**
     * How a session compared with your own earlier best on each exercise it shares with earlier sessions.
     * index = mean of (today's e1RM / earlier best), 1.00 = matched your best. null when nothing is comparable.
     * @param list<array<string,mixed>> $sessions
     * @return array{index:?float,beat:int,matched:int,down:int,compared:int}
     */
    public static function sessionIndex(array $sessions, string $date): array
    {
        $ratios = [];
        $beat = $matched = $down = 0;
        $today = null;
        foreach ($sessions as $s) {
            if ($s['date'] === $date) {
                $today = $s;
            }
        }
        if ($today === null) {
            return ['index' => null, 'beat' => 0, 'matched' => 0, 'down' => 0, 'compared' => 0];
        }
        $before = array_values(array_filter($sessions, fn ($s) => $s['date'] < $date));
        foreach ($today['exercises'] as $ex) {
            $now = self::bestSet($ex['sets']);
            $hist = self::history($before, (string) $ex['id']);
            if (! $now || ! $hist) {
                continue;
            }
            $prev = max(array_column($hist, 'e1rm'));
            $r = $now['e1rm'] / $prev;
            $ratios[] = $r;
            if ($r > self::NEW_BEST) {
                $beat++;
            } elseif ($r >= 0.98) {
                $matched++;
            } else {
                $down++;
            }
        }

        return ['index' => $ratios ? round(array_sum($ratios) / count($ratios), 3) : null, 'beat' => $beat, 'matched' => $matched, 'down' => $down, 'compared' => count($ratios)];
    }

    /**
     * Median change in e1RM, over exercises with at least 4 sessions, from the average of their first two to the average of their last two.
     * A rough "am I getting stronger" number to set beside the weight trend; it is NOT a measure of muscle gained.
     * @param list<array<string,mixed>> $sessions
     * @return array{change_pct:?float,exercises:int}
     */
    public static function strengthChange(array $sessions): array
    {
        $ids = [];
        foreach ($sessions as $s) {
            foreach ($s['exercises'] as $ex) {
                $ids[(string) $ex['id']] = true;
            }
        }
        $changes = [];
        foreach (array_keys($ids) as $id) {
            $h = self::history($sessions, (string) $id);
            if (count($h) < 4) {
                continue;
            }
            $first = ($h[0]['e1rm'] + $h[1]['e1rm']) / 2;
            $last = ($h[count($h) - 1]['e1rm'] + $h[count($h) - 2]['e1rm']) / 2;
            $changes[] = ($last / $first - 1) * 100;
        }
        if (! $changes) {
            return ['change_pct' => null, 'exercises' => 0];
        }
        sort($changes);
        $m = count($changes);
        $median = $m % 2 ? $changes[intdiv($m, 2)] : ($changes[$m / 2 - 1] + $changes[$m / 2]) / 2;

        return ['change_pct' => round($median, 1), 'exercises' => $m];
    }
}
