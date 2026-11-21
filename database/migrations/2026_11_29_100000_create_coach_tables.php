<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80)->nullable();
            $table->timestamps();
        });

        // tool_trace keeps which tools ran (and what they returned) so a wrong answer can be debugged.
        Schema::create('coach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('coach_threads')->cascadeOnDelete();
            $table->string('role', 10);                  // user | assistant
            $table->text('content');
            $table->json('tool_trace')->nullable();
            $table->boolean('grounded')->default(true);  // false = the answer failed the number check and was replaced
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_messages');
        Schema::dropIfExists('coach_threads');
    }
};
