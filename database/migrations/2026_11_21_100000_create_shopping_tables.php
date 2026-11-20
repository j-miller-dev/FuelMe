<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 10)->default('active');     // active | done
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        // The recipes on a list, with the servings chosen. meal_stamp = the meal's updated_at when the list was last built from it,
        // so "this recipe changed after the list was made" can be shown without silently changing the list.
        Schema::create('shopping_list_recipes', function (Blueprint $table) {
            $table->foreignId('list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->foreignId('meal_id')->nullable()->constrained('meals')->nullOnDelete();
            $table->string('meal_name', 160);
            $table->decimal('servings', 6, 2);
            $table->decimal('yield_portions', 6, 2);              // yield when the list was built (scale = servings / yield)
            $table->timestamp('meal_stamp')->nullable();
            $table->index('list_id');
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->string('ingredient_key', 200);
            $table->foreignId('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->string('label', 200);
            $table->decimal('qty', 10, 2)->nullable();            // total needed for the recipes (null = "to taste")
            $table->string('unit', 8)->nullable();                // g | ml | count
            $table->decimal('have_qty', 10, 2)->nullable();       // from the pantry when the list was built
            $table->decimal('pack_size', 10, 2)->nullable();      // user-entered, for "2 x 500 g"
            $table->string('aisle', 40);
            $table->string('state', 8)->default('need');          // need | have | bought
            $table->json('source_meals');                         // names of the recipes that use it
            $table->boolean('is_manual')->default(false);
            $table->uuid('client_uuid')->nullable();              // makes an offline "add" safe to replay
            $table->timestamps();
            $table->unique(['list_id', 'ingredient_key']);
            $table->unique(['list_id', 'client_uuid']);
        });

        Schema::create('pantry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ingredient_key', 200);
            $table->string('label', 200);
            $table->decimal('qty', 10, 2)->nullable();
            $table->string('unit', 8)->nullable();
            $table->boolean('is_staple')->default(false);          // "always have": goes straight to Have on every new list
            $table->timestamps();
            $table->unique(['user_id', 'ingredient_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_items');
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_list_recipes');
        Schema::dropIfExists('shopping_lists');
    }
};
