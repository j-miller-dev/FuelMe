<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Search (M2) relies on these. Guarded so the SQLite test database still migrates.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['pg_trgm', 'unaccent', 'btree_gin'] as $ext) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS \"$ext\"");
        }
    }

    public function down(): void
    {
        // Extensions are shared database objects; dropping them could break other schemas. Intentionally a no-op.
    }
};
