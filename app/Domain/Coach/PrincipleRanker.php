<?php

namespace App\Domain\Coach;

/**
 * Picks the few principle cards that fit a question. Deliberately simple and explainable (word overlap, no embeddings): title 3, tags 2, body 1,
 * +50% when you said "follow", +25% for a "strong" grade. "Ignore" cards are never returned. Pure PHP.
 */
final class PrincipleRanker
{
    private const STOP = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'it', 'my', 'me', 'i', 'do', 'how', 'what', 'should', 'with', 'at', 'be', 'am', 'are', 'about', 'when', 'why', 'this', 'that', 'can'];

    /**
     * @param list<array{id:int|string,title:string,body:string,tags:list<string>,grade:string,stance:string}> $cards
     * @return list<array<string,mixed>> best first, at most $limit
     */
    public static function rank(array $cards, string $query, int $limit = 3): array
    {
        $words = self::words($query);
        if (! $words) {
            return [];
        }
        $scored = [];
        foreach ($cards as $c) {
            if ($c['stance'] === 'ignore') {
                continue;
            }
            $title = self::words($c['title']);
            $tags = array_map('strtolower', $c['tags']);
            $body = self::words($c['body']);
            $s = 0.0;
            foreach ($words as $w) {
                $s += 3 * (int) in_array($w, $title, true) + 2 * (int) in_array($w, $tags, true) + 1 * (int) in_array($w, $body, true);
            }
            if ($s <= 0) {
                continue;
            }
            $s *= ($c['stance'] === 'follow' ? 1.5 : 1.0) * ($c['grade'] === 'strong' ? 1.25 : 1.0);
            $scored[] = ['s' => $s, 'c' => $c];
        }
        usort($scored, fn ($a, $b) => $b['s'] <=> $a['s'] ?: strcmp((string) $a['c']['id'], (string) $b['c']['id']));

        return array_map(fn ($x) => $x['c'], array_slice($scored, 0, max(1, $limit)));
    }

    /** @return list<string> */
    public static function words(string $s): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($s), $m);

        return array_values(array_unique(array_filter($m[0], fn ($w) => strlen($w) > 2 && ! in_array($w, self::STOP, true))));
    }
}
