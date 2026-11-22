<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * Turns "rolled five rounds, hard, about an hour with drilling, then 40 minutes heavy lifting" into session cards (BLUEPRINT 9A.4).
 * Rule based and conservative, like the meal parser's basic mode: it never invents a duration (rounds are not minutes), an unknown
 * activity becomes a custom entry that needs a name and a MET from you, and anything it cannot place is returned as unparsed.
 * Energy is NOT computed here: it comes from ExerciseCalculator when the session is saved. Pure PHP.
 */
final class WorkoutTextParser
{
    private const NUM = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
        'fifteen' => 15, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'ninety' => 90];
    private const WORDNUM = 'zero|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|fifteen|twenty|thirty|forty|fifty|sixty|ninety';

    private const HARD_BJJ = '/\b(hard|intense|intensely|competition|comp|flat out|gruelling|grueling|brutal)\b/';
    private const LIGHT = '/\b(light|easy|flow|flowing|technique|technical|chill|chilled|gentle|recovery)\b/';

    /**
     * @param string $liftingDefault 'hit' or 'standard': what a plain "lifting" means for you
     * @return array{items:list<array<string,mixed>>,unparsed:list<string>}
     */
    public static function parse(string $text, string $liftingDefault = 'hit'): array
    {
        $t = strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
        $t = str_replace(['–', '—'], '-', $t);
        $day = str_contains($t, 'yesterday') ? 'yesterday' : 'today';

        $items = [];
        $unparsed = [];
        foreach (self::segments($t) as $seg) {
            $item = self::item($seg, $liftingDefault, $day);
            if ($item === null) {
                if (trim($seg, " ,") !== '') {
                    $unparsed[] = trim($seg, " ,");
                }
                continue;
            }
            $items[] = $item;
        }

        return ['items' => $items, 'unparsed' => $unparsed];
    }

    /** @return list<string> */
    private static function segments(string $t): array
    {
        $parts = preg_split('/\b(?:and then|then|after that|afterwards|followed by|plus)\b|(?:;|\.(?!\d))|\s\+\s/u', $t) ?: [$t];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p, " ,");
            if ($p === '') {
                continue;
            }
            // "bjj and lifting": split on "and" only when both sides name an activity.
            $sub = preg_split('/\s+and\s+/u', $p) ?: [$p];
            $merged = [];
            foreach ($sub as $x) {
                if ($merged && self::continues($merged[count($merged) - 1], $x)) {
                    $merged[count($merged) - 1] .= ' and ' . $x;
                } else {
                    $merged[] = $x;
                }
            }
            array_push($out, ...$merged);
        }

        return $out;
    }

    /** Does "$next" (after an "and") belong to the same session as "$prev"? */
    private static function continues(string $prev, string $next): bool
    {
        $pt = self::type($prev);
        $nt = self::type($next);
        [$pm] = self::duration($prev);
        [$nm, $nrest] = self::duration($next);
        // "an hour and 15 minutes": the right side is only a duration.
        if ($nm !== null && trim((string) preg_replace('/\b(?:and|about|around|roughly|a|an|the|of|for|bit|more)\b|[,\s]/u', '', $nrest)) === '') {
            return true;
        }
        // "drilling and rolling for an hour": the same activity with the time said once.
        if ($pt !== null && $pt === $nt && ($pm === null || $nm === null)) {
            return true;
        }

        // "bjj and then" words with no activity of their own are a continuation too (e.g. a modifier).
        return $nt === null && $nm === null && ! preg_match('/\d/', $next);
    }

    private static function type(string $s): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(norwegian|4\s?x\s?4)\b/', $s) => 'norwegian_4x4',
            (bool) preg_match('/\b(hiit|tabata|burpees?|intervals?)\b/', $s) => 'hiit',
            (bool) preg_match('/\b(bjj|jiu\W?jitsu|jujitsu|jits|rolled|rolling|rolls?|spar(?:ring|red)?|grappl\w*|no-?gi|gi class|drill(?:ing|ed)?|open mat)\b/', $s) => 'bjj',
            (bool) preg_match('/\b(lift(?:ing|ed)?|weights?|gym|resistance|strength|squats?|deadlifts?|bench|hit (?:session|workout|lifting)|weight training)\b/', $s) => 'lifting',
            default => null,
        };
    }

    /** @return ?array<string,mixed> */
    private static function item(string $s, string $liftingDefault, string $day): ?array
    {
        $type = self::type($s);
        [$minutes, $rest] = self::duration($s);
        if ($type === null && $minutes === null) {
            return null;
        }
        $needs = [];
        $name = null;
        if ($type === null) {
            $type = 'custom';
            $intensity = 'custom';
            $name = trim(preg_replace('/\b(?:about|around|roughly|approximately|approx|did|a|an|the|of|for|some|my|usual)\b|[,~]/u', ' ', $rest) ?? $rest);
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
            $needs[] = 'met';
            if ($name === '') {
                $needs[] = 'name';
            }
        } else {
            $intensity = self::intensity($type, $s, $liftingDefault);
        }
        if ($minutes === null || $minutes < 5 || $minutes > 600) {
            $minutes = null;
            $needs[] = 'minutes';
        }

        $rpe = null;
        if (preg_match('/\brpe\s*(\d+(?:\.\d+)?)\b/', $s, $m) || preg_match('/\b(\d+(?:\.\d+)?)\s*\/\s*10\b/', $s, $m)) {
            $v = (float) $m[1];
            $rpe = $v >= 1 && $v <= 10 ? $v : null;
        }
        $start = null;
        if (preg_match('/\bat\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/', $s, $m) && (isset($m[2]) && $m[2] !== '' || isset($m[3]))) {
            $h = (int) $m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            if (isset($m[3]) && $m[3] !== '') {
                $h = $h % 12 + ($m[3] === 'pm' ? 12 : 0);
            }
            if ($h <= 23 && $min <= 59) {
                $start = sprintf('%02d:%02d', $h, $min);
            }
        }

        return ['text' => $s, 'type' => $type, 'intensity' => $intensity, 'minutes' => $minutes, 'rpe' => $rpe, 'start' => $start, 'name' => $name, 'day' => $day,
            'approximate' => (bool) preg_match('/\b(about|around|roughly|approx\w*|ish)\b|~/', $s), 'needs' => $needs];
    }

    private static function intensity(string $type, string $s, string $liftingDefault): string
    {
        switch ($type) {
            case 'bjj':
                if (preg_match(self::HARD_BJJ, $s)) {
                    return 'hard';
                }
                if (preg_match(self::LIGHT, $s)) {
                    return 'light';
                }
                // Drilling with no rolling is technique work.
                if (preg_match('/\bdrill/', $s) && ! preg_match('/\b(rolled|rolling|rolls?|spar\w*|live)\b/', $s)) {
                    return 'light';
                }

                return 'standard';
            case 'lifting':
                return match (true) {
                    (bool) preg_match('/\b(hit|mentzer|failure)\b/', $s) => 'hit',
                    (bool) preg_match('/\b(heavy|heavier|max|vigorous)\b/', $s) => 'heavy',
                    (bool) preg_match(self::LIGHT, $s) => 'light',
                    default => $liftingDefault === 'standard' ? 'standard' : 'hit',
                };
            case 'hiit':
                return preg_match('/\b(all out|tabata|burpees?|vigorous|max|hard)\b/', $s) ? 'vigorous' : 'moderate';
            default:
                return 'standard';
        }
    }

    /**
     * Total minutes named in the text ("an hour", "40 minutes", "1 hour 20 minutes", "an hour and a half", "half an hour").
     * "Rounds" are never turned into minutes. @return array{0:?int,1:string} minutes and the text with the duration words removed
     */
    private static function duration(string $s): array
    {
        $total = 0.0;
        $found = false;
        $add = function (float $min) use (&$total, &$found) {
            $total += $min;
            $found = true;
        };
        $w = self::WORDNUM;
        $n = '(\d+(?:\.\d+)?|(?:' . $w . ')(?:[ -](?:one|two|three|four|five|six|seven|eight|nine))?)';

        $s = preg_replace_callback('/\b' . $n . ' and a half (hours?|hrs?)\b/', function ($m) use ($add) { $add((self::toNum($m[1]) + 0.5) * 60); return ' '; }, $s) ?? $s;
        $s = preg_replace_callback('/\b(?:an? )?(?:hour and a half|hour and half)\b/', function () use ($add) { $add(90); return ' '; }, $s) ?? $s;
        $s = preg_replace_callback('/\bhalf an? hour\b|\bhalf-hour\b/', function () use ($add) { $add(30); return ' '; }, $s) ?? $s;
        $s = preg_replace_callback('/\b' . $n . ' ?(hours?|hrs?|h)\b/', function ($m) use ($add) { $add(self::toNum($m[1]) * 60); return ' '; }, $s) ?? $s;
        $s = preg_replace_callback('/\ban? hour\b/', function () use ($add) { $add(60); return ' '; }, $s) ?? $s;
        $s = preg_replace_callback('/\b' . $n . ' ?(minutes?|mins?|min|m)\b/', function ($m) use ($add) { $add(self::toNum($m[1])); return ' '; }, $s) ?? $s;

        return [$found ? (int) round($total) : null, $s];
    }

    private static function toNum(string $v): float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }
        $parts = preg_split('/[ -]/', $v) ?: [];
        $sum = 0;
        foreach ($parts as $p) {
            $sum += self::NUM[$p] ?? 0;
        }

        return (float) $sum;
    }
}
