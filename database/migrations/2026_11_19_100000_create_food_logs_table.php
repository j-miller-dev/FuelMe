<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every log stores a SNAPSHOT of name and macros (BLUEPRINT rule): editing or deleting a food never rewrites history.
        Schema::create('food_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');                       // the user's day (rolls over at profile.rollover_hour), not UTC
            $table->timestampTz('logged_at');
            $table->string('meal_slot', 12);                  // breakfast | lunch | dinner | snack
            $table->foreignId('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->text('name');
            $table->decimal('grams', 8, 2)->nullable();       // null for a quick-add (raw macros)
            $table->string('serving_label', 80)->nullable();
            $table->decimal('kcal', 7, 2);
            $table->decimal('protein', 6, 2);
            $table->decimal('carbs', 6, 2);
            $table->decimal('fat', 6, 2);
            $table->decimal('fibre', 6, 2)->nullable();
            $table->string('source', 10);                     // search | quick | copy (scan, capture arrive later)
            $table->uuid('idempotency_key')->nullable()->unique(); // a retried request must not log twice
            $table->timestamps();

            $table->index(['user_id', 'local_date']);
            $table->index(['user_id', 'food_id', 'logged_at']);
        });

        Schema::create('food_favourites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_favourites');
        Schema::dropIfExists('food_logs');
    }
};
