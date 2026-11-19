<?php
declare(strict_types=1);

namespace App\Domain\Foods;

use App\Domain\Search\QueryParser;
use App\Domain\Search\Synonyms;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid food search on PostgreSQL (BLUEPRINT 5.3): weighted tsvector + pg_trgm word similarity over unaccented text,
 * AU synonyms applied at query time, ranked by
 *   score = 0.40 trigram + 0.20 token match + 0.20 personal + 0.10 source weight + 0.10 data quality - missing-macro penalty
 * A personal alias (a phrase the user has taught us) outranks everything.
 * Not yet in the personal term: the same-time-of-day boost. It needs per-log history, which arrives with food logging (M3).
 */
final class FoodSearch
{
    public const LIMIT = 25;
    private const MAX_VARIANTS = 6;
    private const TYPO_FALLBACK_BELOW = 5;

    public function __construct(private Synonyms $synonyms) {}

    /**
     * @return array{query:array{term:string,qty:?float,unit:?string,variants:list<string>}, results:list<array<string,mixed>>}
     */
    public function search(string $raw, ?int $userId, int $limit = self::LIMIT): array
    {
        $parsed = QueryParser::parse($raw);
        $variants = $this->variants($parsed['term']);
        $meta = ['term' => $parsed['term'], 'qty' => $parsed['qty'], 'unit' => $parsed['unit'], 'variants' => $variants];

        if (mb_strlen(trim($parsed['term'])) < 2) {
            return ['query' => $meta, 'results' => []];
        }

        // Phase 1 (fast): exact-word/prefix matches via the tsvector index, plus the user's personal alias.
        // Phase 2 (only when that finds little): add trigram matches so typos still work ("zuchini", "chiken brest").
        $bind = $this->bindings($variants, $parsed['term'], $userId, $limit);
        $rows = DB::select($this->sql($variants, false), $this->only($bind, false, count($variants)));
        if (count($rows) < self::TYPO_FALLBACK_BELOW) {
            // A misspelt multi-word phrase scores ~0.5 word-similarity; the default 0.6 cut-off would drop it. Candidates are
            // still ranked by that same score, so loosening the threshold here only widens the net for typos.
            $rows = DB::transaction(function () use ($variants, $bind) {
                DB::statement("SELECT set_config('pg_trgm.word_similarity_threshold', '0.45', true)");

                return DB::select($this->sql($variants, true), $this->only($bind, true, count($variants)));
            });
        }
        $results = $this->hydrate($rows, $parsed['qty'], $parsed['unit'], $userId);

        return ['query' => $meta, 'results' => $results];
    }

    /** Foods in the given order, hydrated like search results (portion/last-used/favourite included). Unknown or invisible ids are dropped. */
    public function byIds(array $ids, ?int $userId): array
    {
        if (!$ids) {
            return [];
        }
        $rows = DB::table('foods')->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId ?? 0))->get()
            ->map(fn ($r) => (object) ((array) $r + ['score' => 0]))->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
            }
        }

        return $this->hydrate($ordered, null, null, $userId);
    }

    /** Synonym expansion plus singular forms ("2 eggs" must find "Egg, chicken, whole"), deduped and capped. */
    private function variants(string $term): array
    {
        $out = [];
        foreach ($this->synonyms->expand($term) as $v) {
            $out[] = $v;
            $single = self::singular($v);
            if ($single !== $v) {
                $out[] = $single;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, self::MAX_VARIANTS);
    }

    /** Naive English singular of every word ("eggs" -> "egg", "berries" -> "berry", "tomatoes" -> "tomato"). */
    public static function singular(string $phrase): string
    {
        return implode(' ', array_map(function (string $w) {
            return match (true) {
                strlen($w) <= 3 => $w,
                (bool) preg_match('/(ss|us|is)$/', $w) => $w,
                (bool) preg_match('/[^aeiou]ies$/', $w) => substr($w, 0, -3) . 'y',
                (bool) preg_match('/(ch|sh|x|z|o)es$/', $w) => substr($w, 0, -2),
                str_ends_with($w, 's') => substr($w, 0, -1),
                default => $w,
            };
        }, explode(' ', $phrase)));
    }

    /** Lowercased alphanumeric tokens of a phrase; this also makes tsquery input injection-proof. */
    public static function tokens(string $phrase): array
    {
        $phrase = mb_strtolower(self::stripAccents($phrase));

        return preg_split('/[^a-z0-9]+/', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** to_tsquery text: variants OR'd, tokens within a variant AND'd, last token prefix-matched ("chick" finds chicken). */
    public static function tsQuery(array $variants): string
    {
        $parts = [];
        foreach ($variants as $v) {
            $t = self::tokens($v);
            if (!$t) {
                continue;
            }
            $t[array_key_last($t)] .= ':*';
            $parts[] = '(' . implode(' & ', $t) . ')';
        }

        return implode(' | ', $parts);
    }

    private static function stripAccents(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        return $t === false ? $s : $t;
    }

    private function sql(array $variants, bool $withTrigram): string
    {
        $r = range(0, count($variants) - 1);
        // Each variant is its own OR term on the exact indexed expression so the trigram GIN index is usable.
        $trgmBranch = $withTrigram
            ? 'UNION SELECT f.id FROM foods f WHERE (f.user_id IS NULL OR f.user_id = :uid3) AND ('
              . implode(' OR ', array_map(fn ($i) => "CAST(:w$i AS text) <% f.name_norm", $r)) . ')'
            : '';
        $wordSim = implode(', ', array_map(fn ($i) => "word_similarity(CAST(:s$i AS text), c.name_norm)", $r));
        // Token match also rewards a name that STARTS with the query, or a later comma-separated part that is the query as a whole word: "Milk, cow, fluid"
        // over "Ice confection, milk-based", and AFCD's "Beef, mince, raw" alongside OFF's "Mince" (AFCD puts the category first).
        $startsWith = 'GREATEST(' . implode(', ', array_map(
            fn ($i) => "(CASE WHEN replace(c.name_norm, ',', '') LIKE CAST(:p$i AS text) OR c.name_norm LIKE CAST(:q$i AS text)"
                . " OR c.name_norm LIKE CAST(:r$i AS text) OR c.name_norm LIKE CAST(:u$i AS text) THEN 1.0 ELSE 0.0 END)", $r)) . ')';

        // Candidate ids come from separate index-friendly branches (an OR with a subquery forces a sequential scan).
        return <<<SQL
        WITH ids AS (
            SELECT f.id FROM foods f
            WHERE (f.user_id IS NULL OR f.user_id = :uid) AND f.tsv @@ to_tsquery('simple', :tsq)
            UNION SELECT food_id FROM food_aliases WHERE user_id = :uid2 AND phrase = :phrase
            {$trgmBranch}
        ),
        cand AS (SELECT f.* FROM foods f JOIN ids ON ids.id = f.id)
        SELECT c.id, c.source, c.name, c.brand, c.barcode, c.stores, c.category, c.state, c.is_liquid, c.density_g_ml, c.needs_check, c.quality,
               c.per100_kcal, c.per100_protein, c.per100_carbs, c.per100_fat, c.per100_fibre, c.per100_sugar,
               c.per100_sat_fat, c.per100_sodium, c.use_count, c.last_used_at,
               (
                 0.40 * GREATEST({$wordSim})
               + 0.20 * (0.5 * ts_rank(c.tsv, to_tsquery('simple', :tsq2), 32) + 0.5 * {$startsWith})
               + 0.20 * (LEAST(1.0, ln(1 + c.use_count) / ln(51))
                         * CASE WHEN c.last_used_at IS NULL THEN 0 ELSE exp(-extract(epoch FROM now() - c.last_used_at) / 86400.0 / 30) END)
               + 0.10 * CASE c.source WHEN 'user' THEN 1.0 WHEN 'recipe' THEN 1.0 WHEN 'afcd' THEN 0.8 ELSE 0.6 END
               + 0.10 * (c.quality / 100.0)
               - CASE WHEN c.per100_kcal IS NULL OR c.per100_protein IS NULL OR c.per100_carbs IS NULL OR c.per100_fat IS NULL THEN 0.25 ELSE 0 END
               + CASE WHEN c.id IN (SELECT food_id FROM food_aliases WHERE user_id = :uid4 AND phrase = :phrase2) THEN 10 ELSE 0 END
               ) AS score
        FROM cand c
        ORDER BY score DESC, length(c.name) ASC, c.id ASC
        LIMIT :lim
        SQL;
    }

    /** Named PDO parameters may not be sent if the SQL does not use them, so trim the full set per query shape. */
    private function only(array $bind, bool $withTrigram, int $n): array
    {
        if (!$withTrigram) {
            unset($bind['uid3']);
            foreach (range(0, $n - 1) as $i) {
                unset($bind["w$i"]);
            }
        }

        return $bind;
    }

    private function bindings(array $variants, string $term, ?int $userId, int $limit): array
    {
        $tsq = self::tsQuery($variants) ?: (self::tokens($term)[0] ?? 'x');
        $u = $userId ?? 0;
        $phrase = mb_strtolower(trim($term));
        $b = ['tsq' => $tsq, 'tsq2' => $tsq, 'uid' => $u, 'uid2' => $u, 'uid3' => $u, 'uid4' => $u, 'phrase' => $phrase, 'phrase2' => $phrase, 'lim' => $limit];
        foreach ($variants as $i => $v) {
            $norm = mb_strtolower(self::stripAccents($v));
            $b["w$i"] = $b["s$i"] = $norm;
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $norm);
            $b["p$i"] = $like . '%';
            // A later comma-separated part counts only as a whole word ("..., mince, ..." / "..., mince" / "..., mince pies"),
            // so "milk-based" does not count as starting with "milk".
            $b["q$i"] = '%, ' . $like . ',%';
            $b["r$i"] = '%, ' . $like;
            $b["u$i"] = '%, ' . $like . ' %';
        }

        return $b;
    }

    /** @param list<object> $rows */
    private function hydrate(array $rows, ?float $qty, ?string $unit, ?int $userId): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => $r->id, $rows);
        $servings = DB::table('food_servings')->whereIn('food_id', $ids)->orderBy('id')->get()->groupBy('food_id');
        // The weight you last logged of each food (default portion next time) and which are favourites.
        $last = $userId ? DB::table('food_logs')->whereIn('id', fn ($q) => $q->selectRaw('max(id)')->from('food_logs')
            ->where('user_id', $userId)->whereIn('food_id', $ids)->whereNotNull('grams')->groupBy('food_id'))->pluck('grams', 'food_id') : collect();
        $favs = $userId ? DB::table('food_favourites')->where('user_id', $userId)->whereIn('food_id', $ids)->pluck('food_id')->flip() : collect();

        return array_map(function ($r) use ($servings, $qty, $unit, $last, $favs) {
            $sv = ($servings[$r->id] ?? collect())->map(fn ($s) => ['label' => $s->label, 'grams' => (float) $s->grams])->values()->all();
            $portion = ServingResolver::resolve($qty, $unit, (bool) $r->is_liquid, $r->density_g_ml !== null ? (float) $r->density_g_ml : null, $sv);

            return [
                'id' => (int) $r->id, 'source' => $r->source, 'name' => $r->name, 'brand' => $r->brand, 'barcode' => $r->barcode ?? null,
                'stores' => is_string($r->stores ?? null) ? (json_decode($r->stores, true) ?: []) : (array) ($r->stores ?? []), 'category' => $r->category,
                'state' => $r->state, 'is_liquid' => (bool) $r->is_liquid, 'needs_check' => (bool) $r->needs_check,
                'per100' => [
                    'kcal' => $this->f($r->per100_kcal), 'protein' => $this->f($r->per100_protein), 'carbs' => $this->f($r->per100_carbs),
                    'fat' => $this->f($r->per100_fat), 'fibre' => $this->f($r->per100_fibre), 'sugar' => $this->f($r->per100_sugar),
                    'sat_fat' => $this->f($r->per100_sat_fat), 'sodium_mg' => $this->f($r->per100_sodium),
                ],
                'servings' => $sv,
                'portion' => $portion,
                'last_grams' => isset($last[$r->id]) ? (float) $last[$r->id] : null,
                'is_favourite' => isset($favs[$r->id]),
                'score' => round((float) $r->score, 4),
            ];
        }, $rows);
    }

    private function f(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
