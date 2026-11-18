<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('sex', 6);                                  // male | female
            $table->date('dob');
            $table->decimal('height_cm', 5, 1);
            $table->decimal('body_fat_pct', 4, 1)->nullable();
            $table->string('tz', 64)->default('Australia/Melbourne');
            $table->string('units', 8)->default('metric');
            $table->string('energy_unit', 4)->default('kcal');         // display only: kcal | kj
            $table->unsignedSmallInteger('rollover_hour')->default(3); // day rolls over at 03:00 local
            $table->string('job', 16)->default('mixed');               // desk | mixed | on_feet | heavy
            $table->string('shift', 16)->default('day');               // day | afternoon | night | varied
            $table->string('training_age', 8)->default('1to3');        // lt1 | 1to3 | gt3
            $table->string('lifting_style', 8)->default('hit');        // hit | volume
            $table->string('carb_mode', 16)->default('flexible');      // flexible | cap | keto
            $table->decimal('protein_g_per_kg_override', 3, 2)->nullable();
            $table->timestamp('disclaimer_ack_at')->nullable();        // "not medical advice" (9.7)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
