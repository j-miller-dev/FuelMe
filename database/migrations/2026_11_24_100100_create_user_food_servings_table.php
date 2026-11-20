<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Your own household measures for any food ("1 egg" = 50 g). Shared AFCD/OFF rows stay untouched; these are merged in on read.
        Schema::create('user_food_servings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->string('label', 60);
            $table->decimal('grams', 8, 2);
            $table->timestamps();
            $table->unique(['user_id', 'food_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_food_servings');
    }
};
