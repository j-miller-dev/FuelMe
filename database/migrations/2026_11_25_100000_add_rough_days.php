<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A food entry you flagged as a rough estimate ("takeaway about 900"): used, but with a wider error.
        Schema::table('food_logs', fn (Blueprint $t) => $t->boolean('is_rough')->default(false));

        // "Roughly on target" for a whole day. One per day; the day's real log wins if it ends up bigger.
        Schema::create('day_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->string('kind', 12);          // on_target | under | over
            $table->decimal('kcal', 7, 2);        // what the day was recorded as, set by the server from that day's target
            $table->timestamps();
            $table->unique(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_estimates');
        Schema::table('food_logs', fn (Blueprint $t) => $t->dropColumn('is_rough'));
    }
};
