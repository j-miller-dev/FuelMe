<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = shared reference food
            $table->string('source', 10);            // user | afcd | off | recipe
            $table->string('source_id')->nullable(); // AFCD Public Food Key, etc.
            $table->string('barcode', 32)->nullable();
            $table->text('name');
            $table->text('brand')->nullable();
            $table->jsonb('stores')->nullable();
            $table->text('category')->nullable();
            $table->string('state', 10)->nullable(); // raw | cooked | dry | as_sold
            $table->decimal('per100_kcal', 7, 2)->nullable();
            $table->decimal('per100_protein', 6, 2)->nullable();
            $table->decimal('per100_carbs', 6, 2)->nullable();
            $table->decimal('per100_fat', 6, 2)->nullable();
            $table->decimal('per100_fibre', 6, 2)->nullable();
            $table->decimal('per100_sugar', 6, 2)->nullable();
            $table->decimal('per100_sat_fat', 6, 2)->nullable();
            $table->decimal('per100_sodium', 8, 2)->nullable();
            $table->boolean('is_liquid')->default(false); // per100 values are per 100 mL when true
            $table->decimal('density_g_ml', 5, 3)->nullable();
            $table->unsignedSmallInteger('quality')->default(50);
            $table->boolean('needs_check')->default(false);
            $table->timestampTz('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->unique('barcode');
            $table->index(['user_id', 'last_used_at']);
        });

        Schema::create('food_servings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('grams', 8, 2);
        });

        // Long tail of nutrients (AFCD has up to ~268), keyed by the INFOODS-style tag the importer assigns.
        Schema::create('food_nutrients', function (Blueprint $table) {
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->string('nutrient');
            $table->decimal('per100', 12, 4);
            $table->primary(['food_id', 'nutrient']);
        });

        // Personal phrase -> food overrides (BLUEPRINT 6.6). Written by M6; read by search now.
        Schema::create('food_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phrase');
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'phrase']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return; // The SQLite test database gets the tables but not the Postgres-only search indexes.
        }

        // unaccent() is only STABLE, which Postgres refuses in a generated column or an index expression.
        // This wrapper pins the dictionary, which is the standard way to make it IMMUTABLE.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        DB::statement("ALTER TABLE foods ADD COLUMN tsv tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('simple', f_unaccent(coalesce(name, ''))), 'A') ||
            setweight(to_tsvector('simple', f_unaccent(coalesce(brand, ''))), 'B') ||
            setweight(to_tsvector('simple', f_unaccent(coalesce(category, ''))), 'C')) STORED");
        // Normalised name, computed once at write time so search does not call f_unaccent() per candidate row.
        DB::statement('ALTER TABLE foods ADD COLUMN name_norm text GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        DB::statement('CREATE INDEX foods_tsv_idx ON foods USING gin (tsv)');
        DB::statement('CREATE INDEX foods_name_trgm ON foods USING gin (name_norm gin_trgm_ops)');
        DB::statement("ALTER TABLE foods ADD CONSTRAINT foods_source_chk CHECK (source IN ('user','afcd','off','recipe'))");
        DB::statement("ALTER TABLE foods ADD CONSTRAINT foods_state_chk CHECK (state IS NULL OR state IN ('raw','cooked','dry','as_sold'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('food_aliases');
        Schema::dropIfExists('food_nutrients');
        Schema::dropIfExists('food_servings');
        Schema::dropIfExists('foods');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
        }
    }
};
