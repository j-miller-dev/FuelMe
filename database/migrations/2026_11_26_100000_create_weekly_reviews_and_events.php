<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');                 // the Sunday-ending week's first day
            $table->json('review_json');                // every number lives here
            $table->text('note_text');                  // <= 150 words, numbers interpolated or checked against review_json
            $table->string('generated_by', 12);         // template | model
            $table->timestamps();
            $table->unique(['user_id', 'week_start']);
        });

        // One upcoming event (competition or target weight). phases = optional bulk-then-cut timeline.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->date('event_date');
            $table->decimal('target_kg', 5, 1);
            $table->json('phases')->nullable();         // [{until: Y-m-d, target_kg: n}]
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('weekly_reviews');
    }
};
