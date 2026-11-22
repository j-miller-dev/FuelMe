<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hevy_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key');                         // encrypted (Crypt), never sent to the browser
            $table->timestamp('cursor_at')->nullable();      // incremental sync reads workout events since this moment
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('templates_synced_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamps();
        });
        Schema::create('hevy_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_id', 64);
            $table->string('title', 120);
            $table->string('muscle', 40)->nullable();
            $table->json('secondary');
            $table->unique(['user_id', 'template_id']);
        });
        Schema::create('hevy_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('hevy_id', 64);
            $table->string('title', 120);
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->date('local_date');
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'hevy_id']);
            $table->index(['user_id', 'local_date']);
        });
        Schema::create('hevy_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hevy_workout_id')->constrained('hevy_workouts')->cascadeOnDelete();
            $table->unsignedSmallInteger('exercise_index');
            $table->string('template_id', 64);
            $table->string('exercise_title', 120);
            $table->unsignedSmallInteger('set_index');
            $table->string('type', 10)->default('normal');
            $table->decimal('weight_kg', 7, 2)->nullable();
            $table->unsignedSmallInteger('reps')->nullable();
            $table->decimal('rpe', 3, 1)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hevy_sets');
        Schema::dropIfExists('hevy_workouts');
        Schema::dropIfExists('hevy_templates');
        Schema::dropIfExists('hevy_connections');
    }
};
