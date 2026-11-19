<?php

namespace App\Console\Commands;

use App\Domain\Foods\OffImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportOff extends Command
{
    protected $signature = 'foods:import-off
        {path? : Local OFF CSV export (.csv or .csv.gz); default storage/imports/off/off.csv.gz}
        {--download : Download the latest export first (about 1.5 GB)}
        {--cleanup : Delete the downloaded file afterwards}
        {--limit= : Stop after this many Australian rows (for trying it out)}';
    protected $description = 'Import the Australian subset of the Open Food Facts export (ODbL; attribution shown in Settings)';

    private const URL = 'https://static.openfoodfacts.org/data/en.openfoodfacts.org.products.csv.gz';

    public function handle(): int
    {
        $path = $this->argument('path') ?? storage_path('imports/off/off.csv.gz');

        if ($this->option('download')) {
            @mkdir(dirname($path), 0775, true);
            $this->info('Downloading ' . self::URL . ' ...');
            // OFF asks automated clients for a custom User-Agent. Keep it generic unless you set your own contact.
            Http::withUserAgent(config('fuel.off_user_agent'))->timeout(3600)->sink($path)->get(self::URL)->throw();
        }

        $importer = new OffImporter(fn (int $seen, array $s) => $this->output->write("\r  scanned " . number_format($seen) . " rows, AU {$s['au']}, imported {$s['imported']}"));
        $r = $importer->import($path, $this->option('limit') !== null ? (int) $this->option('limit') : null);

        if ($this->option('cleanup')) {
            @unlink($path);
        }

        $this->newLine();
        $this->info("Scanned {$r['seen']} rows; {$r['au']} Australian; imported {$r['imported']}.");
        foreach ($r['rejected'] as $why => $n) {
            $this->line("  rejected $why: $n");
        }
        $this->line('Run foods:reindex afterwards.');

        return self::SUCCESS;
    }
}
