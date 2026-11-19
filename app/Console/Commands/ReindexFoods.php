<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexFoods extends Command
{
    protected $signature = 'foods:reindex';
    protected $description = 'Rebuild the foods search indexes and refresh planner statistics (Postgres)';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Search indexes are Postgres-only; nothing to do.');

            return self::SUCCESS;
        }
        // tsv is a generated column, so it is always current; this just defragments the GIN indexes and updates stats.
        DB::statement('REINDEX TABLE foods');
        DB::statement('ANALYZE foods');
        $this->info('Reindexed and analysed foods.');

        return self::SUCCESS;
    }
}
