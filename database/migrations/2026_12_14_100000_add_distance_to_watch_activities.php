<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_activities', function (Blueprint $table) {
            $table->unsignedInteger('distance_m')->nullable()->after('minutes');
            $table->unsignedInteger('moving_secs')->nullable()->after('distance_m');
        });
    }

    public function down(): void
    {
        Schema::table('watch_activities', function (Blueprint $table) {
            $table->dropColumn(['distance_m', 'moving_secs']);
        });
    }
};
