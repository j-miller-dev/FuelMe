<?php
declare(strict_types=1);

namespace App\Domain\Capture;

/**
 * Re-ranks FoodSearch candidates for one parsed item (brand, store and raw/cooked matter here), then grades how far to trust the top
 * pick (BLUEPRINT 6.1 step 5): green = confident, amber = picked but check, red = no good match, choose or create. Pure.
 */
final class MatchScorer
{
    /** Words that make a food a part or a processed form of the plain one. */
    private const UNLESS_ASKED = ['yolk', 'albumen', 'powder', 'dried', 'dehydrated', 'concentrate', 'flavoured', 'substitute', 'infant', 'mix', 'bowl', 'sandwich', 'burger'];

    public const RED_BELOW_BASE = 0.30;
    public const GREEN_AT = 0.60;
    public const GREEN_GAP = 0.05;

    /**
     * @param list<array<string,mixed>> $cands FoodSearch results (each has 'score', 'name', 'brand', 'stores')
     * @param array<string,mixed> $item
     * @return list<array<string,mixed>> same candidates plus 'match' (0..1.4) and 'why' (list<string>), best first
     */
    public static function rank(array $cands, array $item): array
    {
        foreach ($cands as &$c) {
            $why = [];
            $m = (float) $c['score'] >= 10 ? 1.4 : max(0.0, min(1.0, (float) $c['score']));
            $c['base'] = round($m, 4);   // the raw text-match strength, before any preference below; "no close match" is judged on this
            if ((float) $c['score'] >= 10) {
                $why[] = 'you picked this before';
            }
            $name = mb_strtolower($c['name'] . ' ' . ($c['brand'] ?? ''));
            if (! empty($item['brand']) && str_contains($name, mb_strtolower($item['brand']))) {
                $m += 0.30;
                $why[] = 'brand match';
            }
            if (! empty($item['store']) && (in_array($item['store'], array_map('mb_strtolower', $c['stores'] ?? []), true) || str_contains($name, $item['store']))) {
                $m += 0.40;
                $why[] = ucfirst($item['store']);
            }
            $state = $item['state'] ?? null;
            if ($state === 'cooked' || $state === 'raw') {
                $cooked = (bool) preg_match('/\b(cooked|grilled|roasted|fried|boiled|baked|steamed|stir-fried|scrambled|poached)\b/u', $name);
                $isRaw = (bool) preg_match('/\braw\b/u', $name);
                if (($state === 'cooked' && $cooked) || ($state === 'raw' && $isRaw)) {
                    $m += 0.12;
                    $why[] = $state;
                } elseif (($state === 'cooked' && $isRaw) || ($state === 'raw' && $cooked)) {
                    $m -= 0.08;
                }
            }
            if (($c['source'] ?? '') === 'user') {
                $why[] = 'yours';
            }
            // A plain food ("eggs", "skim milk") means the generic database food, not a packaged product that happens to share words.
            // With a brand or store named, that preference is off and the brand/store boosts above decide.
            if (empty($item['brand']) && empty($item['store'])) {
                $m += match ($c['source'] ?? '') { 'user', 'recipe' => 0.30, 'afcd' => 0.30, default => 0.0 };
            }
            // Parts and processed forms are not the everyday food unless the person said so (yolk, powder, "mix").
            $q = ' ' . mb_strtolower($item['name_query'] ?? '') . ' ';
            foreach (self::UNLESS_ASKED as $w) {
                if (preg_match('/\b' . $w . '\b/u', $name) && ! str_contains($q, $w)) {
                    $m -= 0.08;
                    break;
                }
            }
            // "apple" is the food called Apple, not "Fruit drink, apple juice" or "Pie, sweet, apple": a name that starts with the
            // query wins ties. Plain raw produce beats a frozen or processed variant when no state was given.
            $qn = trim(mb_strtolower(preg_replace('/\s+/u', ' ', (string) ($item['name_query'] ?? '')) ?? ''));
            $head = self::head($c['name']);
            $toks = array_values(array_filter(preg_split('/[^\p{L}\p{N}&\-]+/u', $qn) ?: []));
            $words = preg_split('/[^\p{L}\p{N}&\-]+/u', $name) ?: [];
            $stem = fn (string $w) => rtrim($w, 's');
            $stemmed = array_map($stem, $words);
            if (count($toks) > 1 && ! array_diff(array_map($stem, $toks), $stemmed)) {
                $m += 0.10;   // every word of a multi-word query is in the name, in any order ("fried egg" = "Egg, ..., fried")
            }
            if (count($toks) === 1 && $qn !== '' && ($head === $qn || $head === $qn . 's' || $head . 's' === $qn || str_starts_with($head, $qn . ' '))) {
                $m += 0.10;
            }
            if ($state === null && preg_match('/, raw$/u', mb_strtolower($c['name']))) {
                $m += 0.03;
            }
            // The closer the name is to what was said, the better: every extra word is a small penalty ("Rice, white, fried with bacon or
            // ham, egg, prawns & vegetables" is a dish, not a fried egg).
            $extra = count(array_diff($stemmed, array_map($stem, $toks)));
            $m -= min(0.15, 0.01 * $extra);
            $c['match'] = round($m, 4);
            $c['why'] = $why;
        }
        unset($c);
        usort($cands, fn ($a, $b) => $b['match'] <=> $a['match']);

        return $cands;
    }

    /** The first comma-separated part of an AFCD-style name: "Apple, fuji, raw" -> "apple". */
    private static function head(string $name): string
    {
        return trim(mb_strtolower(explode(',', $name)[0]));
    }

    /**
     * @param list<array<string,mixed>> $ranked output of rank()
     * @param array<string,mixed> $portion PortionResolver result
     * @return array{confidence:string, notes:list<string>}
     */
    public static function grade(array $ranked, array $item, array $portion): array
    {
        $notes = [];
        if ($ranked === []) {
            return ['confidence' => 'red', 'notes' => ['No match found']];
        }
        $top = $ranked[0];
        if (($top['base'] ?? $top['match']) < self::RED_BELOW_BASE && ! array_intersect(['brand match', 'you picked this before', 'yours'], $top['why'])) {
            return ['confidence' => 'red', 'notes' => ['No close match']];
        }
        $level = 'green';
        $usual = in_array('you picked this before', $top['why'], true);
        // Other varieties of the same food ("Apple, fuji" vs "Apple, bonza") are not a rival: any of them is a fine answer.
        $rival = 0.0;
        foreach (array_slice($ranked, 1) as $r) {
            if (self::head($r['name']) !== self::head($top['name'])) {
                $rival = $r['match'];
                break;
            }
        }
        $gap = $top['match'] - $rival;
        if (! $usual && ($top['match'] < self::GREEN_AT || $gap < self::GREEN_GAP)) {
            $level = 'amber';
        }
        if (! empty($item['brand']) && ! in_array('brand match', $top['why'], true) && ! in_array(mb_strtolower($item['brand']), ['weetbix', 'weet-bix', 'tim tam', 'vegemite', 'milo', 'up&go'], true)) {
            $level = 'amber';
            $notes[] = ucfirst($item['brand']) . ' not in the database, showing the generic food';
        }
        if (! empty($item['store']) && ! in_array(ucfirst($item['store']), $top['why'], true)) {
            $level = 'amber';
            $notes[] = 'Generic: ' . ucfirst($item['store']) . ' version not in the database';
        }
        if ($portion['needs_amount']) {
            $level = 'amber';
            $notes[] = $portion['note'] ?? 'Check the amount';
        } elseif ($portion['estimated'] && $level === 'green') {
            $level = 'amber';
            $notes[] = 'Amount is an estimate';
        }
        if ($portion['note'] && ! $portion['needs_amount']) {
            $notes[] = $portion['note'];
        }

        return ['confidence' => $level, 'notes' => $notes];
    }
}
