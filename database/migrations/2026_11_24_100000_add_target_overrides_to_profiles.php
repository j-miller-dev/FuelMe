<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Your own adjustments, kept apart from the app's recommendation so the recommendation (and the weekly check-in) never changes them.
            $table->unsignedSmallInteger('protein_g_override')->nullable();      // fixed grams per day; wins over the g/kg value
            $table->decimal('fat_min_g_per_kg', 3, 2)->nullable();               // raises the fat floor, never lowers it
            $table->smallInteger('kcal_offset')->default(0);                     // +/- kcal per day on top of the recommended target
            $table->json('hit_rules')->nullable();                               // tolerances for the last-5-days strip
        });
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $t) => $t->dropColumn(['protein_g_override', 'fat_min_g_per_kg', 'kcal_offset', 'hit_rules']));
    }
};
