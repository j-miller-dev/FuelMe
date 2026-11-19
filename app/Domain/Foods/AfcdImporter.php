<?php
declare(strict_types=1);

namespace App\Domain\Foods;

use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Imports AFCD Release 3 (FSANZ) from the three Excel downloads in one directory:
 *   Food-Details.xlsx, Nutrient-profiles.xlsx, Food-group-information.xlsx (the last is optional: only for categories).
 * Re-running is safe: foods are upserted on (source, source_id) and their long-tail nutrients replaced.
 */
final class AfcdImporter
{
    /** @var array<string,string> 2-digit group id => name */
    private array $groups = [];

    /** @param null|callable(string):void $log */
    public function __construct(private $log = null) {}

    /** @return array{foods:int, liquids:int, nutrients:int} */
    public function import(string $dir): array
    {
        $dir = rtrim($dir, '/');
        foreach (['Food-Details.xlsx', 'Nutrient-profiles.xlsx'] as $f) {
            if (!is_file("$dir/$f")) {
                throw new \RuntimeException("Missing $dir/$f (see docs/DATA.md for the download links).");
            }
        }
        $this->groups = is_file("$dir/Food-group-information.xlsx") ? $this->readGroups("$dir/Food-group-information.xlsx") : [];

        $details = $this->readDetails("$dir/Food-Details.xlsx");
        $profiles = "$dir/Nutrient-profiles.xlsx";

        // Liquids come from the per-100 mL sheet (that is how people log milk or juice); everything else per 100 g.
        // Find the liquid keys first so the per-100 g pass can skip them instead of writing them twice.
        $liquidKeys = $this->sheetKeys($profiles, true);
        $foods = 0;
        $nutrients = 0;
        foreach ([false, true] as $isLiquid) {
            [$f, $n] = $this->importSheet($profiles, $isLiquid, $details, $liquidKeys);
            $foods += $f;
            $nutrients += $n;
        }
        return ['foods' => $foods, 'liquids' => count($liquidKeys), 'nutrients' => $nutrients];
    }

    /** @return array<string,true> food keys listed on the (liquid or solid) profile sheet */
    private function sheetKeys(string $path, bool $liquid): array
    {
        $keys = [];
        $r = new Reader();
        $r->open($path);
        foreach ($r->getSheetIterator() as $sheet) {
            if (!$this->wantSheet($sheet->getName(), $liquid)) {
                continue;
            }
            $started = false;
            foreach ($sheet->getRowIterator() as $row) {
                $k = $row->toArray()[0] ?? null;
                if (!$started) {
                    $started = $k === 'Public Food Key';
                } elseif ($k !== null && $k !== '') {
                    $keys[(string) $k] = true;
                }
            }
        }
        $r->close();
        return $keys;
    }

    private function wantSheet(string $name, bool $liquid): bool
    {
        $name = mb_strtolower($name);
        return $liquid ? str_contains($name, 'liquids only') : str_contains($name, 'all solids');
    }

    /** @return array{0:int,1:int} */
    private function importSheet(string $path, bool $isLiquid, array $details, array $liquidKeys): array
    {
        $foods = 0;
        $nutrients = 0;
        $header = [];
        $batch = [];
        $r = new Reader();
        $r->open($path);
        foreach ($r->getSheetIterator() as $sheet) {
            if (!$this->wantSheet($sheet->getName(), $isLiquid)) {
                continue;
            }
            foreach ($sheet->getRowIterator() as $row) {
                $v = $row->toArray();
                if (!$header) {
                    if (($v[0] ?? null) === 'Public Food Key') {
                        foreach ($v as $i => $h) {
                            $header[AfcdMapper::normaliseHeader((string) $h)] = $i;
                        }
                    }
                    continue;
                }
                $key = (string) ($v[0] ?? '');
                if ($key === '' || !isset($details[$key]) || (!$isLiquid && isset($liquidKeys[$key]))) {
                    continue;
                }
                $batch[] = [$key, $v];
                if (count($batch) >= 200) {
                    [$f, $n] = $this->flush($batch, $header, $details, $isLiquid);
                    $foods += $f;
                    $nutrients += $n;
                    $batch = [];
                }
            }
        }
        $r->close();
        if ($batch) {
            [$f, $n] = $this->flush($batch, $header, $details, $isLiquid);
            $foods += $f;
            $nutrients += $n;
        }
        return [$foods, $nutrients];
    }

    /** @return array<string,array{class:string, derivation:string, name:string, sg:?float}> */
    private function readDetails(string $path): array
    {
        $out = [];
        $r = new Reader();
        $r->open($path);
        foreach ($r->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== 'Food details') {
                continue;
            }
            $started = false;
            foreach ($sheet->getRowIterator() as $row) {
                $v = $row->toArray();
                if (!$started) {
                    $started = ($v[0] ?? null) === 'Public Food Key';
                    continue;
                }
                if (($v[0] ?? '') === '') {
                    continue;
                }
                $out[(string) $v[0]] = [
                    'class' => (string) ($v[1] ?? ''), 'derivation' => (string) ($v[2] ?? ''),
                    'name' => trim((string) ($v[3] ?? '')), 'sg' => AfcdMapper::num($v[8] ?? null),
                ];
            }
        }
        $r->close();
        return $out;
    }

    /** @return array<string,string> */
    private function readGroups(string $path): array
    {
        $out = [];
        $r = new Reader();
        $r->open($path);
        foreach ($r->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== 'Food group information') {
                continue;
            }
            foreach ($sheet->getRowIterator() as $row) {
                $v = $row->toArray();
                if (is_numeric($v[0] ?? null) && ($v[1] ?? '') !== '') {
                    $out[(string) (int) $v[0]] = trim((string) $v[1]);
                }
            }
        }
        $r->close();
        return $out;
    }

    /**
     * @param list<array{0:string,1:array}> $batch
     * @param array<string,int> $header
     * @return array{0:int,1:int} foods written, nutrient rows written
     */
    private function flush(array $batch, array $header, array $details, bool $isLiquid): array
    {
        $now = now();
        $foodRows = [];
        $skip = [AfcdMapper::COL_ENERGY, 'public food key', 'classification', 'derivation', 'food name'];

        foreach ($batch as [$key, $row]) {
            $d = $details[$key];
            $cell = fn (string $col) => isset($header[$col]) ? AfcdMapper::num($row[$header[$col]] ?? null) : null;
            $carbs = null;
            foreach (AfcdMapper::COL_CARBS as $c) {
                $carbs ??= $cell($c);
            }
            $kcal = AfcdMapper::kjToKcal($row[$header[AfcdMapper::COL_ENERGY] ?? -1] ?? null);
            $group = $this->groups[substr($d['class'], 0, 2)] ?? null;
            $foodRows[] = [
                'user_id' => null, 'source' => 'afcd', 'source_id' => $key, 'name' => $d['name'], 'brand' => null,
                'category' => $group, 'state' => AfcdMapper::state($d['name']),
                'per100_kcal' => $kcal, 'per100_protein' => $cell(AfcdMapper::COL_PROTEIN), 'per100_carbs' => $carbs,
                'per100_fat' => $cell(AfcdMapper::COL_FAT), 'per100_fibre' => $cell(AfcdMapper::COL_FIBRE),
                'per100_sugar' => $cell(AfcdMapper::COL_SUGAR), 'per100_sat_fat' => $cell(AfcdMapper::COL_SAT_FAT),
                'per100_sodium' => $cell(AfcdMapper::COL_SODIUM),
                'is_liquid' => $isLiquid, 'density_g_ml' => $isLiquid && ($d['sg'] ?? 0) > 0 ? $d['sg'] : null,
                'quality' => AfcdMapper::quality($d['derivation']), 'needs_check' => false,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $written = 0;
        $nutrientRows = 0;
        DB::transaction(function () use ($foodRows, $batch, $header, $skip, &$written, &$nutrientRows) {
            DB::table('foods')->upsert(
                $foodRows, ['source', 'source_id'],
                array_values(array_diff(array_keys($foodRows[0]), ['source', 'source_id', 'user_id', 'created_at'])),
            );
            $written = count($foodRows);

            $ids = DB::table('foods')->where('source', 'afcd')->whereIn('source_id', array_column($batch, 0))
                ->pluck('id', 'source_id');
            DB::table('food_nutrients')->whereIn('food_id', $ids->values())->delete();

            $rows = [];
            foreach ($batch as [$key, $row]) {
                foreach ($header as $col => $i) {
                    if (in_array($col, $skip, true) || !isset($row[$i])) {
                        continue;
                    }
                    $n = AfcdMapper::num($row[$i]);
                    if ($n === null) {
                        continue;
                    }
                    $rows[] = ['food_id' => $ids[$key], 'nutrient' => AfcdMapper::nutrientKey($col), 'per100' => $n];
                }
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('food_nutrients')->insert($chunk);
            }
            $nutrientRows = count($rows);
        });

        return [$written, $nutrientRows];
    }
}
