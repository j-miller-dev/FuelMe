<?php
declare(strict_types=1);

namespace App\Domain\Foods;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Barcode -> food (BLUEPRINT 5.4): local database first (scanned product, UPC/EAN spellings), then one live Open Food Facts
 * lookup whose result is cached in `foods` for good, so each product is fetched at most once. Misses are remembered for a few hours
 * so a rescan does not hit OFF again. Statuses: found | invalid | not_found | incomplete | offline.
 */
final class BarcodeLookup
{
    private const FIELDS = 'code,product_name,generic_name,brands,stores,categories,quantity,serving_size,serving_quantity,nutriments,countries_tags';
    private const MISS_TTL_HOURS = 6;

    public function __construct(private FoodSearch $search) {}

    /** @return array{status:string, code:string, food?:array<string,mixed>, prefill?:array<string,mixed>} */
    public function find(string $raw, ?int $userId): array
    {
        $code = Gtin::clean($raw);

        $local = $this->local($code, $userId);
        if ($local) {
            return ['status' => 'found', 'code' => $code, 'food' => $local];
        }
        if (!Gtin::valid($code)) {
            return ['status' => 'invalid', 'code' => $code];   // bad length or check digit: almost certainly a misread
        }
        if (Cache::has("barcode-miss:$code")) {
            return ['status' => 'not_found', 'code' => $code];
        }

        try {
            $res = Http::withUserAgent((string) config('fuel.off_user_agent'))->timeout(6)->acceptJson()
                ->get(rtrim((string) config('fuel.off_api_base'), '/') . "/api/v2/product/{$code}", ['fields' => self::FIELDS]);
        } catch (ConnectionException) {
            return ['status' => 'offline', 'code' => $code];
        }
        if ($res->status() === 404) {
            Cache::put("barcode-miss:$code", true, now()->addHours(self::MISS_TTL_HOURS));

            return ['status' => 'not_found', 'code' => $code];
        }
        if (!$res->successful()) {
            return ['status' => 'offline', 'code' => $code];   // rate limit or OFF outage: try again later, do not cache a miss
        }

        $body = $res->json();
        if (($body['status'] ?? 0) !== 1 || !is_array($body['product'] ?? null)) {
            Cache::put("barcode-miss:$code", true, now()->addHours(self::MISS_TTL_HOURS));

            return ['status' => 'not_found', 'code' => $code];
        }

        $p = $body['product'];
        $row = ['code' => $code, 'categories_en' => $p['categories'] ?? ''] + $p + (is_array($p['nutriments'] ?? null) ? $p['nutriments'] : []);
        $mapped = OffMapper::map($row);

        if (isset($mapped['reject'])) {
            // The product exists but its nutrition data is missing or unusable: hand back what we know to pre-fill "create it".
            return ['status' => 'incomplete', 'code' => $code, 'reason' => $mapped['reject'], 'prefill' => [
                'barcode' => $code, 'name' => trim((string) ($p['product_name'] ?? $p['generic_name'] ?? '')),
                'brand' => trim(explode(',', (string) ($p['brands'] ?? ''))[0]), 'serving_label' => $p['serving_size'] ?? null,
                'serving_grams' => is_numeric($p['serving_quantity'] ?? null) ? (float) $p['serving_quantity'] : null,
            ]];
        }

        OffStore::save([$mapped]);

        return ['status' => 'found', 'code' => $code, 'food' => $this->local($code, $userId)];
    }

    /** @return array<string,mixed>|null */
    private function local(string $code, ?int $userId): ?array
    {
        if ($code === '') {
            return null;
        }
        $id = DB::table('foods')->whereIn('barcode', Gtin::variants($code))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId ?? 0))
            ->orderByRaw("case source when 'user' then 0 else 1 end")->value('id');

        return $id ? ($this->search->byIds([(int) $id], $userId)[0] ?? null) : null;
    }
}
