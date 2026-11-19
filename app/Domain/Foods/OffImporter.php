<?php
declare(strict_types=1);

namespace App\Domain\Foods;

use Illuminate\Support\Facades\DB;

/**
 * Streams the Open Food Facts CSV export (tab separated, optionally .gz) and imports the Australian subset.
 * Lines that do not mention en:australia are skipped before parsing, so the 10+ GB export is cheap to scan.
 * Re-running upserts on the barcode, so it is safe to schedule weekly.
 */
final class OffImporter
{
    private const BATCH = 500;

    /** @param null|callable(int,array):void $progress called every batch with (seen, stats) */
    public function __construct(private $progress = null) {}

    /** @return array{seen:int, au:int, imported:int, rejected:array<string,int>} */
    public function import(string $path, ?int $limit = null): array
    {
        $uri = str_ends_with($path, '.gz') ? "compress.zlib://$path" : $path;
        $h = @fopen($uri, 'r');
        if (!$h) {
            throw new \RuntimeException("Cannot open $path");
        }
        $header = explode("\t", rtrim((string) fgets($h), "\r\n"));
        foreach (['code', 'product_name', 'countries_tags'] as $need) {
            if (!in_array($need, $header, true)) {
                throw new \RuntimeException("Not an Open Food Facts CSV export (no '$need' column).");
            }
        }
        $width = count($header);

        $seen = $au = $imported = 0;
        $rejected = [];
        $batch = [];
        while (($line = fgets($h)) !== false) {
            $seen++;
            if (!str_contains($line, 'en:australia')) {
                continue;
            }
            $cells = explode("\t", rtrim($line, "\r\n"));
            if (count($cells) !== $width) {
                $rejected['malformed line'] = ($rejected['malformed line'] ?? 0) + 1;
                continue;
            }
            $row = array_combine($header, $cells);
            if (!preg_match('/(^|,)en:australia(,|$)/', $row['countries_tags'] ?? '')) {
                continue; // the string appeared elsewhere in the row (e.g. a store name)
            }
            $au++;
            $m = OffMapper::map($row);
            if (isset($m['reject'])) {
                $rejected[$m['reject']] = ($rejected[$m['reject']] ?? 0) + 1;
                continue;
            }
            $batch[] = $m;
            if (count($batch) >= self::BATCH) {
                $imported += $this->flush($batch);
                $batch = [];
                if ($this->progress) {
                    ($this->progress)($seen, compact('au', 'imported'));
                }
            }
            if ($limit !== null && $au >= $limit) {
                break;
            }
        }
        fclose($h);
        if ($batch) {
            $imported += $this->flush($batch);
        }

        return ['seen' => $seen, 'au' => $au, 'imported' => $imported, 'rejected' => $rejected];
    }

    /** @param list<array{food:array,serving:?array}> $batch */
    private function flush(array $batch): int
    {
        $now = now();
        // The same barcode can appear twice in a dump; keep the last. Skip barcodes the user already owns (unique barcode).
        $byBarcode = [];
        foreach ($batch as $m) {
            $byBarcode[$m['food']['barcode']] = $m;
        }
        $owned = DB::table('foods')->whereIn('barcode', array_keys($byBarcode))->where('source', '!=', 'off')->pluck('barcode')->all();
        $byBarcode = array_diff_key($byBarcode, array_flip($owned));
        if (!$byBarcode) {
            return 0;
        }

        $rows = array_map(fn ($m) => $m['food'] + ['created_at' => $now, 'updated_at' => $now], array_values($byBarcode));
        $update = array_values(array_diff(array_keys($rows[0]), ['source', 'source_id', 'user_id', 'barcode', 'created_at']));

        DB::transaction(function () use ($rows, $byBarcode, $update) {
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
        });

        return count($rows);
    }
}
