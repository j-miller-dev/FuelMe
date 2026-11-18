<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->unsignedInteger('kcal');
            $table->unsignedInteger('protein');
            $table->unsignedInteger('fat');
            $table->unsignedInteger('carbs');
            $table->string('reason', 32);                        // onboarding | profile_edit | weekly_checkin ...
            $table->jsonb('plan_json');                          // full planner output + prior, for the "why" sheet
            $table->timestamps();

            $table->index(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_history');
    }
};
