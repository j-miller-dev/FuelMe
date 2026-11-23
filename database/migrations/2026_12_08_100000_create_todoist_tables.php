<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todoist_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_token');                        // encrypted (Crypt), never sent to the browser
            $table->string('project_id', 40)->nullable();     // the "FUEL shopping" project, created on the first export
            $table->timestamps();
        });
        // Which list items already went to Todoist, so sending the list twice never adds an item twice.
        Schema::create('todoist_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('list_item_id')->constrained('shopping_list_items')->cascadeOnDelete();
            $table->string('task_id', 40);
            $table->timestamps();
            $table->unique(['user_id', 'list_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todoist_exports');
        Schema::dropIfExists('todoist_connections');
    }
};
