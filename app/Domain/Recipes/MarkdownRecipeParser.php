<?php
declare(strict_types=1);

namespace App\Domain\Recipes;

/**
 * Reads one recipe note (Obsidian-style markdown) into a title, a serving count and a list of ingredient lines. Pure: it never
 * looks anything up and never produces nutrition. Matching lines to foods happens later, and a person confirms every match.
 *
 * Handles frontmatter (title, servings/serves/yield/makes, tags), an "Ingredients" heading with sub-group headings, bullets,
 * numbered lines, task checkboxes, [[wikilinks]], emphasis and unicode/plain fractions. When there is no Ingredients heading it
 * falls back to bullet lines that start with an amount, and says so.
 */
final class MarkdownRecipeParser
{
    private const STOP = '/^(method|methods|directions?|instructions?|steps?|preparation|prep|notes?|nutrition|to serve|serving|equipment|tips?)\b/i';
    private const FRACTIONS = ['½' => '.5', '¼' => '.25', '¾' => '.75', '⅓' => '.33', '⅔' => '.67', '⅛' => '.125'];
    private const MAX_LINES = 80;

    /**
     * @return array{title:string,servings:?float,tags:list<string>,ingredients:list<array{line:string,group:?string}>,warnings:list<string>}
     */
    public static function parse(string $markdown, string $fallbackTitle = 'Untitled recipe'): array
    {
        $md = str_replace(["\r\n", "\r", "\xEF\xBB\xBF"], ["\n", "\n", ''], $markdown);
        [$front, $body] = self::frontmatter($md);
        $lines = explode("\n", $body);
        $warnings = [];

        $title = trim((string) ($front['title'] ?? ''));
        if ($title === '') {
            foreach ($lines as $l) {
                if (preg_match('/^#\s+(.+?)\s*#*\s*$/', $l, $m)) {
                    $title = self::plain($m[1]);
                    break;
                }
            }
        }
        $title = $title !== '' ? $title : $fallbackTitle;

        $servings = self::servings($front, $lines);
        $tags = self::tags($front['tags'] ?? null);

        [$ingredients, $found] = self::fromHeading($lines);
        if (! $found) {
            $ingredients = self::fromBullets($lines);
            if ($ingredients) {
                $warnings[] = 'No "Ingredients" heading found, so bullet lines that start with an amount were used. Check the list.';
            }
        }
        if (count($ingredients) > self::MAX_LINES) {
            $ingredients = array_slice($ingredients, 0, self::MAX_LINES);
            $warnings[] = 'Only the first ' . self::MAX_LINES . ' ingredient lines were read.';
        }
        if (! $ingredients) {
            $warnings[] = 'No ingredients found in this note.';
        }

        return ['title' => mb_substr($title, 0, 120), 'servings' => $servings, 'tags' => $tags, 'ingredients' => $ingredients, 'warnings' => $warnings];
    }

    /** Turns one list line into plain text a food parser can read: no bullet, box, link, emphasis or odd fractions. */
    public static function cleanLine(string $line): string
    {
        $s = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/u', '', $line) ?? $line;
        $s = preg_replace('/^\[[ xX]?\]\s*/u', '', $s) ?? $s;                       // task checkbox
        $s = preg_replace('/\[\[([^\]|]*)\|([^\]]*)\]\]/u', '$2', $s) ?? $s;         // [[note|shown]]
        $s = preg_replace('/\[\[([^\]]*)\]\]/u', '$1', $s) ?? $s;                    // [[note]]
        $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $s) ?? $s;               // [text](url)
        $s = preg_replace('/<[^>]+>/u', '', $s) ?? $s;
        $s = preg_replace('/(\*\*|__|`|~~)/u', '', $s) ?? $s;
        $s = preg_replace('/(?<![\w])[*_]|[*_](?![\w])/u', '', $s) ?? $s;            // lone emphasis marks
        $s = preg_replace('/(\d)\s*(½|¼|¾|⅓|⅔|⅛)/u', '$1$2', $s) ?? $s;
        foreach (self::FRACTIONS as $glyph => $dec) {
            $s = preg_replace('/(\d)' . preg_quote($glyph, '/') . '/u', '$1' . $dec, $s) ?? $s;
            $s = str_replace($glyph, '0' . $dec, $s);
        }
        $s = preg_replace_callback('/\b(\d+)\s+(\d+)\/(\d+)\b/u', fn ($m) => self::frac((float) $m[1], (float) $m[2], (float) $m[3]), $s) ?? $s;
        $s = preg_replace_callback('/\b(\d+)\/(\d+)\b/u', fn ($m) => self::frac(0.0, (float) $m[1], (float) $m[2]), $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private static function frac(float $whole, float $n, float $d): string
    {
        if ($d <= 0) {
            return (string) $whole;
        }

        return rtrim(rtrim(number_format($whole + $n / $d, 2, '.', ''), '0'), '.');
    }

    private static function plain(string $s): string
    {
        return self::cleanLine($s);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private static function frontmatter(string $md): array
    {
        if (! preg_match('/\A---\n(.*?)\n---\s*(?:\n|\z)/s', $md, $m)) {
            return [[], $md];
        }
        $front = [];
        $key = null;
        foreach (explode("\n", $m[1]) as $l) {
            if (preg_match('/^([A-Za-z_][\w -]*):\s*(.*)$/', $l, $kv)) {
                $key = strtolower(trim($kv[1]));
                $front[$key] = trim($kv[2], " \t\"'");
            } elseif ($key !== null && preg_match('/^\s*-\s+(.+)$/', $l, $li)) {
                $front[$key] = trim(((string) $front[$key]) . ',' . trim($li[1], " \t\"'"), ',');   // YAML list -> comma list
            }
        }

        return [$front, substr($md, strlen($m[0]))];
    }

    /** @param array<string,mixed> $front @param list<string> $lines */
    private static function servings(array $front, array $lines): ?float
    {
        foreach (['servings', 'serves', 'serving', 'yield', 'makes', 'portions'] as $k) {
            if (isset($front[$k]) && preg_match('/(\d+(?:\.\d+)?)/', (string) $front[$k], $m)) {
                return self::sane((float) $m[1]);
            }
        }
        foreach (array_slice($lines, 0, 40) as $l) {
            if (preg_match('/\b(?:serves|servings?|makes|feeds|portions)\b\**\s*[:\-]?\s*\**\s*(\d+(?:\.\d+)?)/i', $l, $m)) {
                return self::sane((float) $m[1]);
            }
        }

        return null;
    }

    private static function sane(float $n): ?float
    {
        return $n >= 1 && $n <= 100 ? $n : null;
    }

    /** @return list<string> */
    private static function tags(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[,\s\[\]]+/', $raw) ?: [] as $t) {
            $t = strtolower(ltrim(trim($t), '#'));
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $lines
     * @return array{0:list<array{line:string,group:?string}>,1:bool} the ingredient lines and whether an Ingredients heading existed
     */
    private static function fromHeading(array $lines): array
    {
        $out = [];
        $inside = false;
        $level = 0;
        $group = null;
        $found = false;
        foreach ($lines as $l) {
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $l, $h)) {
                $depth = strlen($h[1]);
                $text = self::plain($h[2]);
                if (preg_match('/^ingredients?\b/i', $text)) {
                    $inside = true;
                    $found = true;
                    $level = $depth;
                    $group = null;
                    continue;
                }
                if ($inside) {
                    if ($depth <= $level || preg_match(self::STOP, $text)) {
                        $inside = false;
                        continue;
                    }
                    $group = $text;   // a sub-heading such as "Sauce" inside the list
                }
                continue;
            }
            if (! $inside) {
                continue;
            }
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+\S/u', $l) || preg_match('/^\s*\[[ xX]?\]\s+\S/u', $l)) {
                $clean = self::cleanLine($l);
                if ($clean !== '') {
                    $out[] = ['line' => $clean, 'group' => $group];
                }
            }
        }

        return [$out, $found];
    }

    /**
     * No heading: bullets that begin with an amount ("500 g ...", "2 eggs", "1/2 cup ..."), stopping at the method.
     *
     * @param list<string> $lines
     * @return list<array{line:string,group:?string}>
     */
    private static function fromBullets(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^#{1,6}\s+(.*)$/', $l, $h) && preg_match(self::STOP, self::plain($h[1])) && $out) {
                break;
            }
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]?\]\s*)?(?:\d|[½¼¾⅓⅔⅛]|a\s+(?:pinch|dash|splash)|one\b|two\b|three\b|half\b)/iu', $l)) {
                $clean = self::cleanLine($l);
                if ($clean !== '') {
                    $out[] = ['line' => $clean, 'group' => null];
                }
            }
        }

        return $out;
    }
}
