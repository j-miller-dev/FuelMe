<?php
declare(strict_types=1);

namespace App\Domain\Watch;

/**
 * Month summaries and pace/speed for watch activities. Pure PHP. Distance and time come from the watch; a pace or speed that a body could not
 * produce (a GPS glitch) is dropped, never shown. Pace only for running and walking, speed only for cycling, nothing for gym sessions.
 */
final class ActivityStats
{
    private const RUN = ['run', 'trailrun', 'virtualrun', 'walk', 'hike', 'nordicski'];
    private const RIDE = ['ride', 'virtualride', 'gravelride', 'mountainbikeride', 'ebikeride', 'handcycle', 'velomobile'];

    /** @return 'pace'|'speed'|null */
    public static function kind(?string $type): ?string
    {
        $t = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $type) ?? '');

        return in_array($t, self::RUN, true) ? 'pace' : (in_array($t, self::RIDE, true) ? 'speed' : null);
    }

    /** @return array{kind:string,value:float}|null pace = seconds per km, speed = km/h */
    public static function rate(?string $type, ?int $distanceM, ?int $secs): ?array
    {
        $kind = self::kind($type);
        if ($kind === null || ! $distanceM || ! $secs || $distanceM < 100 || $secs < 30) {
            return null;
        }
        $kmh = $distanceM / 1000 / ($secs / 3600);
        if ($kind === 'speed') {
            return $kmh >= 3 && $kmh <= 70 ? ['kind' => 'speed', 'value' => round($kmh, 1)] : null;
        }
        $pace = $secs / ($distanceM / 1000);

        return $pace >= 120 && $pace <= 1200 ? ['kind' => 'pace', 'value' => round($pace, 0)] : null;
    }

    /**
     * @param list<array<string,mixed>> $rows each with date (Y-m-d), minutes, distanceM; newest first or any order
     * @return list<array{month:string,count:int,km:float,minutes:int,items:list<array<string,mixed>>}> newest month first, items newest first
     */
    public static function months(array $rows): array
    {
        usort($rows, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        $by = [];
        foreach ($rows as $r) {
            $m = substr((string) $r['date'], 0, 7);
            $by[$m] ??= ['month' => $m, 'count' => 0, 'm' => 0, 'minutes' => 0, 'items' => []];
            $by[$m]['count']++;
            $by[$m]['m'] += (int) ($r['distanceM'] ?? 0);
            $by[$m]['minutes'] += (int) ($r['minutes'] ?? 0);
            $by[$m]['items'][] = $r + ['rate' => self::rate($r['type'] ?? null, $r['distanceM'] ?? null, $r['secs'] ?? null)];
        }
        krsort($by);

        return array_values(array_map(fn ($x) => ['month' => $x['month'], 'count' => $x['count'], 'km' => round($x['m'] / 1000, 2), 'minutes' => $x['minutes'], 'items' => $x['items']], $by));
    }
}
