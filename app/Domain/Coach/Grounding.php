<?php
declare(strict_types=1);

namespace App\Domain\Coach;

/**
 * The coach's number guard (BLUEPRINT 9A.2): every number in an answer must come from that turn's tool results (or from the person's own
 * question), allowing for rounding. It is deliberately strict: "about 3,300" for a result of 3250 is flagged, because the answer would then
 * contain a figure no tool produced. Pure PHP.
 */
final class Grounding
{
    /** @return list<float> numbers in a piece of text, ignoring list markers at the start of a line and thousands separators */
    public static function numbersIn(string $text): array
    {
        $t = preg_replace('/^\s*\d+[.)]\s+/m', '', $text) ?? $text;           // "1. first point"
        $t = preg_replace('/(?<=\d),(?=\d{3}\b)/', '', $t) ?? $t;              // 3,250 -> 3250
        preg_match_all('/\d+(?:\.\d+)?/', $t, $m);

        return array_map('floatval', $m[0]);
    }

    /**
     * @param array<mixed> $toolResults everything the tools returned this turn
     * @return list<float> numbers in $answer that no tool result (or the question) supports
     */
    public static function ungrounded(string $answer, array $toolResults, string $question = ''): array
    {
        $allowed = [];
        $add = function (float $v) use (&$allowed) {
            foreach ([0, 1, 2] as $d) {
                $allowed[(string) round($v, $d)] = true;
                $allowed[(string) round(abs($v), $d)] = true;
            }
        };
        array_walk_recursive($toolResults, function ($v) use ($add) {
            if (is_int($v) || is_float($v)) {
                $add((float) $v);
            } elseif (is_string($v)) {
                foreach (self::numbersIn($v) as $n) {
                    $add($n);
                }
            }
        });
        foreach (self::numbersIn($question) as $n) {
            $add($n);
        }

        $bad = [];
        foreach (self::numbersIn($answer) as $n) {
            if (! isset($allowed[(string) round($n, 2)])) {
                $bad[] = $n;
            }
        }

        return array_values(array_unique($bad));
    }
}
