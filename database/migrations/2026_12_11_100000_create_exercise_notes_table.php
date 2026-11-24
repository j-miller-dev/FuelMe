<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Your own words about one exercise (a cue, what worked). Keyed by the Hevy template id so it survives re-syncs and deleting synced history.
        Schema::create('exercise_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_id', 64);
            $table->string('kind', 10)->default('cue');      // cue | worked | note
            $table->string('body', 600);
            $table->date('local_date');
            $table->timestamps();
            $table->index(['user_id', 'template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_notes');
    }
};
