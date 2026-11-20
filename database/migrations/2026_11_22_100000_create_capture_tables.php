<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per "say or type a meal" request: what was understood and what it cost. The transcript is kept so mistakes can be reviewed.
        Schema::create('capture_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('transcript');
            $table->string('mode', 8);                         // ai | basic
            $table->json('parsed_json')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('note', 200)->nullable();           // why basic mode was used
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // Learning from swaps (BLUEPRINT 6.6): the same table the search already reads for personal aliases.
        Schema::table('food_aliases', function (Blueprint $table) {
            $table->decimal('default_grams', 8, 2)->nullable();   // the portion you usually have of it
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('food_aliases', fn (Blueprint $t) => $t->dropColumn(['default_grams', 'hits', 'last_used_at']));
        Schema::dropIfExists('capture_sessions');
    }
};
