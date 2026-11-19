<?php
declare(strict_types=1);

namespace App\Domain\Foods;

use Illuminate\Support\Facades\DB;

/** Writes mapped Open Food Facts products (OffMapper output) into foods + food_servings. Shared by the bulk importer and live lookups. */
final class OffStore
{
    /**
     * Upserts on the barcode. Never touches a barcode the user already owns. Returns the ids written, keyed by barcode.
     * @param list<array{food:array<string,mixed>,serving:?array{label:string,grams:float}}> $batch
     * @return array<string,int>
     */
    public static function save(array $batch): array
    {
        $now = now();
        $byBarcode = [];
        foreach ($batch as $m) {
            $byBarcode[$m['food']['barcode']] = $m;   // the same barcode can appear twice in a dump; keep the last
        }
        $owned = DB::table('foods')->whereIn('barcode', array_keys($byBarcode))->where('source', '!=', 'off')->pluck('barcode')->all();
        $byBarcode = array_diff_key($byBarcode, array_flip($owned));
        if (!$byBarcode) {
            return [];
        }

        $rows = array_map(fn ($m) => $m['food'] + ['created_at' => $now, 'updated_at' => $now], array_values($byBarcode));
        $update = array_values(array_diff(array_keys($rows[0]), ['source', 'source_id', 'user_id', 'barcode', 'created_at']));

        return DB::transaction(function () use ($rows, $byBarcode, $update) {
            DB::table('foods')->upsert($rows, ['source', 'source_id'], $update);
            $ids = DB::table('foods')->where('source', 'off')->whereIn('source_id', array_keys($byBarcode))->pluck('id', 'source_id');
            DB::table('food_servings')->whereIn('food_id', $ids->values())->delete();
            $servings = [];
            foreach ($byBarcode as $code => $m) {
                if ($m['serving']) {
                    $servings[] = ['food_id' => $ids[$code], 'label' => $m['serving']['label'], 'grams' => $m['serving']['grams']];
                }
            }
            if ($servings) {
                DB::table('food_servings')->insert($servings);
            }

            return $ids->map(fn ($i) => (int) $i)->all();
        });
    }
}
