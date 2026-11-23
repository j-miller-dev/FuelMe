<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', fn (Blueprint $t) => $t->json('creatine_plan')->nullable());
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $t) => $t->dropColumn('creatine_plan'));
    }
};
