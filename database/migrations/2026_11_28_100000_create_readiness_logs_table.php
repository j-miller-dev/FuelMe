<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The 10-second morning check-in. All scores 1-5 where 5 is the good end for sleep and energy, and the sore end for soreness.
        Schema::create('readiness_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->unsignedTinyInteger('sleep');       // 1 poor ... 5 great
            $table->unsignedTinyInteger('soreness');    // 1 none ... 5 very sore
            $table->unsignedTinyInteger('energy');      // 1 flat ... 5 great
            $table->string('notes', 280)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_logs');
    }
};
