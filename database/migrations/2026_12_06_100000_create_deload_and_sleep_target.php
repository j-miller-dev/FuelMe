<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weeks you labelled as a deload (stored as that week's Monday).
        Schema::create('deload_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->timestamps();
            $table->unique(['user_id', 'week_start']);
        });
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('sleep_target_min')->nullable();   // your own sleep target; null = the 8 h default
        });
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $table) => $table->dropColumn('sleep_target_min'));
        Schema::dropIfExists('deload_weeks');
    }
};
