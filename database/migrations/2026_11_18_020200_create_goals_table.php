<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 8);                          // cut | maintain | bulk
            $table->decimal('rate_pct_per_week', 4, 2);          // signed: -0.50 = lose 0.5% BW/week
            $table->date('start_date');
            $table->date('event_date')->nullable();
            $table->string('experience', 12)->nullable();        // novice | intermediate | advanced
            $table->timestamps();

            $table->index(['user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
