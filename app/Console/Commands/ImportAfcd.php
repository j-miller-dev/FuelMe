<?php

namespace App\Console\Commands;

use App\Domain\Foods\AfcdImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportAfcd extends Command
{
    protected $signature = 'foods:import-afcd
        {path? : Directory holding the AFCD Release 3 .xlsx files (default storage/imports/afcd)}
        {--download : Fetch the four Release 3 workbooks from foodstandards.gov.au first (about 4 MB)}
        {--cleanup : Delete the workbooks afterwards}';
    protected $description = 'Import AFCD Release 3 (FSANZ) foods and nutrients (CC BY 4.0 Australia; attribution shown in Settings)';

    // Published file names on foodstandards.gov.au (Release 3, Dec 2025). If FSANZ moves them, download by hand and pass the directory.
    private const BASE = 'https://www.foodstandards.gov.au/sites/default/files/2025-12/AFCD%20Release%203%20-%20';
    private const FILES = [
        'Food-Details.xlsx' => 'Food%20Details.xlsx',
        'Nutrient-profiles.xlsx' => 'Nutrient%20profiles.xlsx',
        'Food-group-information.xlsx' => 'Food%20group%20information.xlsx',
    ];

    public function handle(): int
    {
        $dir = rtrim($this->argument('path') ?? storage_path('imports/afcd'), '/');

        if ($this->option('download')) {
            @mkdir($dir, 0775, true);
            foreach (self::FILES as $local => $remote) {
                $this->line("Downloading $local ...");
                Http::withUserAgent(config('fuel.off_user_agent'))->timeout(300)->sink("$dir/$local")->get(self::BASE . $remote)->throw();
            }
        }

        $this->info("Importing AFCD from $dir ...");
        $r = (new AfcdImporter())->import($dir);
        $this->info("Done: {$r['foods']} foods ({$r['liquids']} liquids, per 100 mL), {$r['nutrients']} long-tail nutrient values.");

        if ($this->option('cleanup')) {
            foreach (array_keys(self::FILES) as $f) {
                @unlink("$dir/$f");
            }
        }
        $this->line('Run foods:reindex afterwards for fresh planner statistics.');

        return self::SUCCESS;
    }
}
