<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The usual week. The planner forecasts from it; a logged day overrides it for that date only.
        Schema::create('training_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('day', 3);                            // mon .. sun
            $table->string('start_time', 5)->nullable();         // "06:30", local; null = time not set
            $table->string('type', 20);
            $table->string('intensity', 20);
            $table->unsignedSmallInteger('minutes');
            $table->decimal('custom_met', 4, 1)->nullable();     // type = custom only
            $table->string('name', 80)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'day']);
        });

        // Sessions on real dates. status: planned (copied from the template, not yet confirmed), done, skipped.
        // If a date has ANY row, the rows are the truth for that date and the template no longer applies to it.
        Schema::create('workout_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->string('status', 8)->default('done');
            $table->string('type', 20);
            $table->string('intensity', 20);
            $table->unsignedSmallInteger('minutes');
            $table->decimal('rpe', 3, 1)->nullable();
            $table->decimal('met', 4, 2);
            $table->decimal('net_kcal', 7, 2);                   // (MET - 1) x kg x hours, an ESTIMATE good to about +/-25%
            $table->decimal('kcal_override', 7, 2)->nullable();  // a watch's number, if you prefer it
            $table->string('start_time', 5)->nullable();
            $table->string('name', 80)->nullable();
            $table->string('source', 10)->default('manual');     // manual | template
            $table->foreignId('template_id')->nullable()->constrained('training_templates')->nullOnDelete();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_logs');
        Schema::dropIfExists('training_templates');
    }
};
