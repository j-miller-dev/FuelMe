<?php
declare(strict_types=1);

namespace App\Domain\Notify;

/**
 * Decides which steps of today's pre-session timeline deserve a phone notification RIGHT NOW. Only the step that needs you to act (the main pre-session
 * meal), never the fluid nudges or the after-session note (a finished session is no longer "next", so that one cannot be planned ahead), and never at night: nothing before 06:00 or after 21:30 local time. A step is due for
 * a short window after its time (so a missed tick is caught) and not after, so you are never told to eat 3 hours late. Pure PHP.
 */
final class ReminderPlanner
{
    public const KINDS = ['meal'];
    public const QUIET_BEFORE = 6 * 60;
    public const QUIET_AFTER = 21 * 60 + 30;
    public const WINDOW_MIN = 15;

    /**
     * @param list<array{atMin:int,kind:string,title:string}> $timeline
     * @return list<array{atMin:int,kind:string,title:string}> due now, in time order
     */
    public static function due(array $timeline, int $nowMin): array
    {
        if ($nowMin < self::QUIET_BEFORE || $nowMin > self::QUIET_AFTER) {
            return [];
        }
        $out = [];
        foreach ($timeline as $s) {
            if (! in_array($s['kind'], self::KINDS, true)) {
                continue;
            }
            if ($s['atMin'] < self::QUIET_BEFORE || $s['atMin'] > self::QUIET_AFTER) {
                continue;   // would have been in the night
            }
            if ($nowMin >= $s['atMin'] && $nowMin < $s['atMin'] + self::WINDOW_MIN) {
                $out[] = $s;
            }
        }
        usort($out, fn ($a, $b) => $a['atMin'] <=> $b['atMin']);

        return $out;
    }

    public static function key(string $date, string $sessionKey, string $kind): string
    {
        return mb_substr("session:{$date}:{$sessionKey}:{$kind}", 0, 120);
    }

    /** Wording kept generic on purpose: it shows on a lock screen. */
    public static function text(string $sessionName, string $sessionClock): array
    {
        return ['title' => 'Pre-session meal', 'body' => "Time to eat before {$sessionName} at {$sessionClock}. Tap for your plan."];
    }
}
