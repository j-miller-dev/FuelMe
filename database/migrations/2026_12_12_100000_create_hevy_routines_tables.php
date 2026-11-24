<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Your Hevy routines, read-only copies. FUEL never writes to Hevy. Routines have no change feed, so each sync replaces the whole set.
        Schema::create('hevy_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('hevy_id', 64);
            $table->string('title', 120);
            $table->string('folder_id', 64)->nullable();
            $table->string('folder_title', 120)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('hevy_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'hevy_id']);
        });
        Schema::create('hevy_routine_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hevy_routine_id')->constrained('hevy_routines')->cascadeOnDelete();
            $table->unsignedSmallInteger('exercise_index');
            $table->string('template_id', 64);
            $table->string('title', 120);
            $table->unsignedSmallInteger('rest_seconds')->nullable();
            $table->string('notes', 1000)->nullable();       // the note you wrote on the exercise inside Hevy
            $table->string('superset_id', 32)->nullable();
            $table->json('sets');                             // planned sets: [{type, kg, reps, rep_min, rep_max}]
        });
        Schema::table('hevy_workouts', function (Blueprint $table) {
            $table->string('routine_id', 64)->nullable()->after('hevy_id');   // which routine a workout was started from, when Hevy says so
        });
        Schema::table('hevy_connections', function (Blueprint $table) {
            $table->timestamp('routines_synced_at')->nullable();
            $table->string('routines_error', 200)->nullable();                // routines failing never fails the workout sync
        });
    }

    public function down(): void
    {
        Schema::table('hevy_connections', fn (Blueprint $t) => $t->dropColumn(['routines_synced_at', 'routines_error']));
        Schema::table('hevy_workouts', fn (Blueprint $t) => $t->dropColumn('routine_id'));
        Schema::dropIfExists('hevy_routine_exercises');
        Schema::dropIfExists('hevy_routines');
    }
};
