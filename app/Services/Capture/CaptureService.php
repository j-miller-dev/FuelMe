<?php

namespace App\Services\Capture;

use App\Domain\Capture\MatchScorer;
use App\Domain\Capture\ParseValidator;
use App\Domain\Capture\PortionResolver;
use App\Domain\Capture\RuleParser;
use App\Domain\Foods\FoodSearch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Say or type a meal -> review cards (BLUEPRINT 6): parse, retrieve, resolve the portion, score. Always returns something usable. */
class CaptureService
{
    public function __construct(private FoodSearch $search, private AnthropicParser $llm, private RuleParser $rules) {}

    /**
     * @return array<string,mixed> the review payload
     */
    public function run(User $user, string $text, ?string $slotHint, ?string $localTime = null): array
    {
        $t0 = hrtime(true);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        [$parsed, $mode, $note, $tokens] = $this->parse($user, $text, $slotHint, $localTime ?? now()->format('D H:i'));

        $items = [];
        $n = 0;
        foreach ($parsed['items'] as $it) {
            $items[] = $this->resolve($user->id, 'i' . (++$n), $it);
        }

        $id = (string) Str::uuid();
        $ms = (int) ((hrtime(true) - $t0) / 1e6);
        DB::table('capture_sessions')->insert(['id' => $id, 'user_id' => $user->id, 'transcript' => mb_substr($text, 0, 2000), 'mode' => $mode,
            'parsed_json' => json_encode($parsed), 'latency_ms' => $ms, 'input_tokens' => $tokens[0], 'output_tokens' => $tokens[1], 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);

        // Red first, then amber, then green: what needs a look comes first.
        $rank = ['red' => 0, 'amber' => 1, 'green' => 2];
        usort($items, fn ($a, $b) => [$rank[$a['confidence']], $a['id']] <=> [$rank[$b['confidence']], $b['id']]);

        return ['session_id' => $id, 'mode' => $mode, 'note' => $note, 'transcript' => $text, 'items' => $items, 'unparsed' => $parsed['unparsed'], 'latency_ms' => $ms];
    }

    /** @return array{0:array{items:list<array>,unparsed:list<string>},1:string,2:?string,3:array{0:int,1:int}} */
    private function parse(User $user, string $text, ?string $slotHint, string $localTime): array
    {
        if ($this->llm->enabled()) {
            $cap = (int) config('fuel.capture.monthly_token_cap');
            $used = (int) DB::table('capture_sessions')->where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth())
                ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as t')->value('t');
            if ($cap > 0 && $used >= $cap) {
                return [$this->rules->parse($text, $slotHint), 'basic', 'AI limit for this month reached', [0, 0]];
            }
            try {
                $personal = $this->personal($user->id);
                $key = 'capture:' . sha1(mb_strtolower($text) . '|' . $slotHint . '|' . implode('|', $personal));
                $r = Cache::get($key);
                $cached = $r !== null;
                $r ??= $this->llm->parse($text, $slotHint, $localTime, $personal);
                if (! $cached) {
                    Cache::put($key, $r, 600);   // the same sentence twice costs one call
                }

                return [['items' => $r['items'], 'unparsed' => $r['unparsed']], 'ai', null, $cached ? [0, 0] : [$r['input_tokens'], $r['output_tokens']]];
            } catch (\Throwable $e) {
                report($e);

                return [$this->rules->parse($text, $slotHint), 'basic', 'AI unavailable', [0, 0]];
            }
        }

        return [$this->rules->parse($text, $slotHint), 'basic', null, [0, 0]];
    }

    /** @return list<string> */
    private function personal(int $userId): array
    {
        $aliases = DB::table('food_aliases')->join('foods', 'foods.id', '=', 'food_aliases.food_id')->where('food_aliases.user_id', $userId)
            ->orderByDesc('food_aliases.hits')->limit(25)->get(['food_aliases.phrase', 'foods.name'])->map(fn ($r) => "{$r->phrase} = {$r->name}")->all();
        $own = DB::table('foods')->where('user_id', $userId)->where('source', 'user')->orderByDesc('use_count')->limit(25)->pluck('name')->all();

        return array_slice(array_merge($aliases, $own), 0, 40);
    }

    /**
     * One ingredient line ("600 g chicken breast", "woolworths chicken breast") -> the same review item the Say tab builds, always with the
     * rule parser (no AI, no cost). Null when nothing food-like can be read.
     *
     * @return array<string,mixed>|null
     */
    public function ingredient(User $user, string $line, string $id): ?array
    {
        $it = $this->rules->parse($line)['items'][0] ?? null;

        return $it === null ? null : $this->resolve($user->id, $id, $it);
    }

    /** @param array<string,mixed> $it @return array<string,mixed> */
    private function resolve(int $userId, string $id, array $it): array
    {
        $cands = $this->retrieve($userId, $it);
        $ranked = MatchScorer::rank($cands, $it);
        $top = $ranked[0] ?? null;

        $aliasGrams = null;
        if ($top && in_array('you picked this before', $top['why'], true)) {
            $aliasGrams = DB::table('food_aliases')->where('user_id', $userId)->where('food_id', $top['id'])->where('phrase', mb_strtolower($it['name_query']))->value('default_grams');
            $aliasGrams = $aliasGrams !== null ? (float) $aliasGrams : null;
        }
        $portion = $top ? PortionResolver::resolve($it, $top, $aliasGrams) : ['grams' => (float) ($it['grams_estimate'] ?? 100), 'estimated' => true, 'needs_amount' => true, 'source' => 'default', 'note' => null];
        $g = MatchScorer::grade($ranked, $it, $portion);

        return [
            'id' => $id, 'phrase' => $it['phrase'], 'parsed' => array_intersect_key($it, array_flip(['name_query', 'brand', 'store', 'state', 'quantity', 'unit', 'is_approximate', 'ambiguity', 'meal_slot'])),
            'candidates' => array_slice($ranked, 0, 8),
            'chosen' => $top ? ['food_id' => $top['id'], 'grams' => $portion['grams'], 'estimated' => $portion['estimated'], 'needs_amount' => $portion['needs_amount'], 'portion_source' => $portion['source']] : null,
            'confidence' => $g['confidence'], 'notes' => $g['notes'], 'meal_slot' => $it['meal_slot'] ?? null,
        ];
    }

    /** Brand + name first, then the name alone, merged by food id (best score wins). @return list<array<string,mixed>> */
    private function retrieve(int $userId, array $it): array
    {
        $queries = [];
        if (! empty($it['brand'])) {
            $queries[] = $it['brand'] . ' ' . $it['name_query'];
        }
        $queries[] = $it['name_query'];
        $merged = [];
        foreach ($queries as $q) {
            foreach ($this->search->search($q, $userId, 250)['results'] as $r) {
                if (! isset($merged[$r['id']]) || $r['score'] > $merged[$r['id']]['score']) {
                    $merged[$r['id']] = $r;
                }
            }
            if (count($merged) >= 5) {
                break;
            }
        }

        return array_values($merged);
    }

    /** Remember what the person confirmed: "porterhouse" now means THEIR porterhouse, at their usual weight. */
    public function learn(int $userId, array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            $phrase = mb_strtolower(trim((string) $it['phrase']));
            if ($phrase === '' || ! DB::table('foods')->where('id', $it['food_id'])->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))->exists()) {
                continue;
            }
            $existing = DB::table('food_aliases')->where('user_id', $userId)->where('phrase', $phrase)->first();
            $row = ['food_id' => $it['food_id'], 'default_grams' => $it['grams'] ?? null, 'last_used_at' => now(), 'updated_at' => now()];
            if ($existing) {
                DB::table('food_aliases')->where('id', $existing->id)->update($row + ['hits' => $existing->hits + 1]);
            } else {
                DB::table('food_aliases')->insert($row + ['user_id' => $userId, 'phrase' => $phrase, 'hits' => 1, 'created_at' => now()]);
            }
            $n++;
        }

        return $n;
    }
}
