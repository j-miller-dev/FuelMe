<?php
declare(strict_types=1);

namespace App\Domain\Capture;

/**
 * Basic-mode parser (BLUEPRINT 6.7): turns "two scrambled eggs, 2 slices of burgen toast with butter, jalna greek yoghurt about 200 grams"
 * into one item per food with NO LLM. Always available, and the fallback whenever the AI is down, capped or returns junk.
 * Pure. It only splits and labels text; it never produces nutrition numbers.
 *
 * Item: ['phrase','name_query','brand','store','state','quantity','unit','grams_estimate','is_approximate','meal_slot','ambiguity']
 */
final class RuleParser
{
    private const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];
    private const FILLERS = '/\b(?:about|around|roughly|approximately|approx|approx\.|maybe|like|just|some|another|my|the|usual|probably|ish|bit of|little)\b|~/u';

    /** @var array<string,mixed> */
    private array $v;

    public function __construct(array $vocab)
    {
        $this->v = $vocab;
    }

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array{items:list<array<string,mixed>>, unparsed:list<string>} */
    public function parse(string $text, ?string $slotHint = null): array
    {
        $text = $this->normalise($text);
        $items = [];
        $unparsed = [];

        // "for lunch ... then for dinner ..." starts a new meal slot.
        $parts = preg_split('/\b(?:then\s+)?for\s+(breakfast|lunch|dinner|snacks?)\b[\s,:\-]*/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $slot = $slotHint;
        foreach ($parts as $k => $part) {
            if ($k % 2 === 1) {
                $slot = rtrim($part, 's') === 'snack' ? 'snack' : $part;   // "snacks" -> snack
                continue;
            }
            foreach ($this->segments($part) as $seg) {
                $item = $this->item($seg, $slot);
                if ($item === null) {
                    if (trim($seg) !== '') {
                        $unparsed[] = trim($seg);
                    }

                    continue;
                }
                $items[] = $item;
            }
        }

        return ['items' => $items, 'unparsed' => $unparsed];
    }

    private function normalise(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = str_replace(['&', "\u{2019}"], [' and ', "'"], $t);
        foreach ($this->v['asr_fixes'] as $bad => $good) {
            $t = preg_replace('/\b' . preg_quote($bad, '/') . '\b/u', $good, $t) ?? $t;
        }

        // "250g" -> "250 g", "x3" -> "x 3"; only before a known unit, so "3pm" and "7up" are left alone.
        $units = implode('|', array_map('preg_quote', array_keys($this->v['units'])));
        $t = preg_replace('/(\d)\s*(' . $units . ')\b/u', '$1 $2', $t) ?? $t;
        $t = preg_replace('/\bx\s*(\d)/u', 'x $1', $t) ?? $t;

        // Dishes whose name contains "and" must not be split into two foods.
        foreach ($this->v['protected'] as $i => $p) {
            $t = str_replace($p, 'PROTECTED' . $i . 'X', $t);
        }

        return preg_replace('/\s+/u', ' ', $t) ?? $t;
    }

    /** @return list<string> */
    private function segments(string $chunk): array
    {
        $chunk = preg_replace('/^\s*(?:i\s+(?:just\s+)?(?:had|ate|have had|am having|drank)|today i had|for\s+\w+\s+i\s+had)\s+/u', '', $chunk) ?? $chunk;
        $segs = preg_split('/\s*(?:,|;|\n|\.\s|\band\b|\bwith\b|\bplus\b|\bthen\b|\balso\b|\bi had\b|\bi ate\b)\s*/u', $chunk) ?: [];

        return array_values(array_filter(array_map(fn ($s) => trim($s, " \t.-"), $segs), fn ($s) => $s !== ''));
    }

    /** @return array<string,mixed>|null */
    private function item(string $seg, ?string $slot): ?array
    {
        $orig = $seg;
        $approx = (bool) preg_match(self::FILLERS, $seg);
        $seg = trim(preg_replace('/\s+/u', ' ', preg_replace(self::FILLERS, ' ', $seg) ?? $seg) ?? $seg);

        // state
        $state = null;
        if (preg_match('/\b(uncooked|raw)\b/u', $seg)) {
            $state = 'raw';
        } elseif (preg_match('/\b(cooked)\b/u', $seg)) {
            $state = 'cooked';
        } elseif (preg_match('/\b(dry)\b/u', $seg)) {
            $state = 'dry';
        }
        $seg = trim(preg_replace('/\b(uncooked|cooked|raw)\b/u', ' ', $seg) ?? $seg);

        [$qty, $unit, $rest] = $this->quantity($seg);
        $rest = trim(preg_replace('/^(?:of|a|an)\s+/u', '', $rest) ?? $rest);
        // Size words describe the piece, they are not part of the food's name ("a medium orange" is an orange).
        $rest = trim(preg_replace('/\b(?:small|medium|large|big|extra large|regular)\s+(?=\w)/u', '', $rest) ?? $rest);

        // store and brand words
        $store = null;
        foreach ($this->v['stores'] as $word => $canon) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $rest)) {
                $store = $canon;
                $rest = trim(preg_replace('/\b' . preg_quote($word, '/') . '(?:\'s)?\b/u', ' ', $rest) ?? $rest);
                break;
            }
        }
        $brand = null;
        foreach ($this->v['brands'] as $b) {
            if (preg_match('/\b' . preg_quote($b, '/') . '\b/u', $rest)) {
                $brand = $b;
                $rest = trim(preg_replace('/\b' . preg_quote($b, '/') . '\b/u', ' ', $rest) ?? $rest);
                break;
            }
        }
        if ($brand === null) {
            foreach ($this->v['brand_foods'] as $b) {
                if (preg_match('/\b' . preg_quote($b, '/') . '\b/u', $rest)) {
                    $brand = $b;   // kept in the query too: the brand IS the food
                    break;
                }
            }
        }

        foreach ($this->v['protected'] as $i => $p) {
            $rest = str_replace('PROTECTED' . $i . 'X', $p, $rest);
        }
        $rest = trim(preg_replace('/\s+/u', ' ', preg_replace('/^(?:of|a|an|some)\s+|\s+(?:of)$/u', '', $rest) ?? $rest) ?? $rest, " ,-");
        if ($rest === '' && $brand === null && $store === null) {
            return null;
        }
        // "aldi porterhouse" -> query "porterhouse"; a bare brand ("jalna") keeps the brand as the query.
        $query = $rest !== '' ? $rest : ($brand ?? $store ?? '');

        [$grams, $q, $u] = [null, $qty, $unit];
        if ($u === 'kg' && $q !== null) { $q *= 1000; $u = 'g'; }
        if ($u === 'l' && $q !== null) { $q *= 1000; $u = 'ml'; }
        if ($u === 'g' || $u === 'ml') { $grams = $q; }

        $ambiguity = [];
        if ($q === null) { $ambiguity[] = 'amount not stated'; }
        if (in_array($u, ['handful', 'scoop', 'serve'], true) && $grams === null) { $ambiguity[] = 'size of a ' . $u . ' not known'; }

        return [
            'phrase' => trim(str_replace(['PROTECTED'], '', $orig)), 'name_query' => $query, 'brand' => $brand, 'store' => $store, 'state' => $state,
            'quantity' => $q, 'unit' => $u, 'grams_estimate' => $grams, 'is_approximate' => $approx, 'meal_slot' => $slot, 'ambiguity' => $ambiguity, 'components' => [],
        ];
    }

    /** @return array{0:?float,1:?string,2:string} quantity, unit, what is left */
    private function quantity(string $s): array
    {
        $words = array_keys($this->v['numbers']);
        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));   // "an" before "a", or "an aldi" would read as the number "a" + "n aldi"
        $num = '(?<n>\d+(?:[.,]\d+)?(?:\s*\/\s*\d+)?|(?:' . implode('|', array_map('preg_quote', $words)) . ')\b)';
        $unit = '(?<u>' . implode('|', array_map('preg_quote', array_keys($this->v['units']))) . ')';

        // leading: "2 slices of toast", "half a cup of milk", "a can of tuna", "half an avocado", "a couple of eggs", "250 g steak", "2 eggs"
        if (preg_match('/^' . $num . '\s*(?:of\s+)?(?:(?:a|an)\s+)?(?:' . $unit . '\b)?\s*(?:of\s+)?(?<rest>.*)$/u', $s, $m) && ($m['n'] !== '' )) {
            $n = $this->number($m['n']);
            $u = isset($m['u']) && $m['u'] !== '' ? $this->v['units'][$m['u']] : null;
            $rest = $m['rest'];
            // "a" / "an" alone only count as a quantity of one when followed by food ("a banana"), which this is.
            if ($n !== null && trim($rest) !== '') {
                // "an aldi porterhouse 250 grams": the leading "an" is only an article when an explicit amount trails the food.
                if (in_array($m['n'], ['a', 'an'], true) && $u === null
                    && preg_match('/^(?<rest>.+?)\s+(?:x\s*)?(?<n>\d+(?:[.,]\d+)?)\s*(?:' . $unit . ')$/u', $rest, $t)) {
                    return [$this->number($t['n']), $this->v['units'][$t['u']], $t['rest']];
                }

                return [$n, $u, $rest];
            }
        }
        // trailing: "weetbix 2", "tim tam x 3", "jalna yoghurt 200 g", "yoghurt 200 grams"
        if (preg_match('/^(?<rest>.+?)\s+(?:x\s*)?(?<n>\d+(?:[.,]\d+)?)\s*(?:' . $unit . ')?$/u', $s, $m)) {
            return [$this->number($m['n']), isset($m['u']) && $m['u'] !== '' ? $this->v['units'][$m['u']] : null, $m['rest']];
        }

        return [null, null, $s];
    }

    private function number(string $s): ?float
    {
        $s = trim($s);
        if (isset($this->v['numbers'][$s])) {
            return (float) $this->v['numbers'][$s];
        }
        if (str_contains($s, '/')) {
            [$a, $b] = array_map('trim', explode('/', $s, 2));

            return (float) $b > 0 ? (float) $a / (float) $b : null;
        }

        return is_numeric(str_replace(',', '.', $s)) ? (float) str_replace(',', '.', $s) : null;
    }
}
