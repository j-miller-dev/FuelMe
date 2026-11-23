<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervals_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key');                         // encrypted (Crypt), never sent to the browser
            $table->string('athlete_id', 32)->default('0');  // "0" = the owner of the API key
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamps();
        });
        // One row per day of what your watch measured overnight and through the day (via Intervals.icu). Any field can be missing.
        Schema::create('watch_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->unsignedInteger('sleep_secs')->nullable();
            $table->unsignedSmallInteger('resting_hr')->nullable();
            $table->decimal('hrv', 5, 1)->nullable();
            $table->unsignedInteger('steps')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'local_date']);
        });
        Schema::create('watch_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ext_id', 40);
            $table->date('local_date');
            $table->string('start_local', 19);
            $table->string('name', 120);
            $table->string('type', 40)->nullable();
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->unsignedSmallInteger('avg_hr')->nullable();
            $table->unsignedSmallInteger('max_hr')->nullable();
            $table->unsignedSmallInteger('load')->nullable();
            $table->string('source', 30)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'ext_id']);
            $table->index(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_activities');
        Schema::dropIfExists('watch_days');
        Schema::dropIfExists('intervals_connections');
    }
};
