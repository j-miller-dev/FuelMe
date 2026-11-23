<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per dose taken (creatine first). Grams are stored as numbers in grams; no calories are attached, so this never touches the food budget.
        Schema::create('supplement_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->date('local_date');
            $table->decimal('amount_g', 6, 2);
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'name', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplement_logs');
    }
};
