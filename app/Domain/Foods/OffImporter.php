<?php
declare(strict_types=1);

namespace App\Domain\Foods;

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
        return count(OffStore::save($batch));
    }
}
