<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('yield_portions', 6, 2)->default(1);   // how many portions the recipe makes
            $table->decimal('cook_loss', 4, 3)->default(0);        // fraction of raw weight lost in cooking (water). Macros do not change.
            $table->json('tags');                                  // breakfast | pre | post | prep | snack
            $table->timestamps();
            $table->index(['user_id', 'name']);
        });

        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained('meals')->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->string('name', 200);                           // kept so a removed food still shows what the ingredient was
            $table->decimal('grams', 8, 2);                        // as purchased / raw weight (shopping reads this later)
            $table->unsignedSmallInteger('sort')->default(0);
        });

        // Meal prep: "I made 8 portions on Sunday". Logging a portion can draw from a batch; remaining = made - logged.
        Schema::create('meal_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_id')->constrained('meals')->cascadeOnDelete();
            $table->decimal('portions', 6, 2);
            $table->date('made_on');
            $table->timestamps();
        });

        Schema::table('food_logs', function (Blueprint $table) {
            $table->foreignId('meal_id')->nullable()->constrained('meals')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('meal_batches')->nullOnDelete();
            $table->decimal('portions', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('food_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropConstrainedForeignId('meal_id');
            $table->dropColumn('portions');
        });
        Schema::dropIfExists('meal_batches');
        Schema::dropIfExists('meal_items');
        Schema::dropIfExists('meals');
    }
};
