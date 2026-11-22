<?php

namespace App\Domain\Training;

/**
 * Session load = how hard you rated a session (1-10) x its minutes ("session RPE" method). Only sessions YOU rated count; unrated ones are listed as
 * unrated, never guessed. A way to compare BJJ, HIIT and lifting on one scale. A rough index, not a measurement. Pure PHP.
 */
final class SessionLoad
{
    /**
     * @param list<array{date:string,minutes:int,rpe:?float}> $sessions done sessions
     * @return array{load:int,rated:int,unrated:int}
     */
    public static function between(array $sessions, string $from, string $to): array
    {
        $load = $rated = $unrated = 0;
        foreach ($sessions as $s) {
            if ($s['date'] < $from || $s['date'] > $to) {
                continue;
            }
            if ($s['rpe'] === null) {
                $unrated++;

                continue;
            }
            $rated++;
            $load += (int) round($s['rpe'] * $s['minutes']);
        }

        return ['load' => $load, 'rated' => $rated, 'unrated' => $unrated];
    }
}
