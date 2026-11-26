<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hevy_connections', function (Blueprint $table) {
            $table->json('shown_folders')->nullable();   // null = automatic (the Rational Intensity P1-P3 folders); otherwise the folder ids you chose
        });
    }

    public function down(): void
    {
        Schema::table('hevy_connections', function (Blueprint $table) {
            $table->dropColumn('shown_folders');
        });
    }
};
