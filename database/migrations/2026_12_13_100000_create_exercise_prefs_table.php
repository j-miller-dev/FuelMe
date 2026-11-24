<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Your own smallest weight jump per exercise (a dumbbell rack jumps 2 kg, a pin stack 5 kg ...). Keyed by Hevy template id.
        Schema::create('exercise_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_id', 64);
            $table->decimal('step_kg', 5, 2);
            $table->timestamps();
            $table->unique(['user_id', 'template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_prefs');
    }
};
