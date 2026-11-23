<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per browser/phone that said yes to notifications. The keys are what the push service needs to deliver an encrypted message to it.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('user_agent', 160)->nullable();
            $table->timestamps();
        });
        // What has already been sent, so a reminder is never repeated (the scheduler may look at the same moment several times).
        Schema::create('push_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 120);
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'dedupe_key']);
        });
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('push_prefs')->nullable();   // {"session_fuel":bool,"checkin":bool}; null = both on once you subscribe
        });
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $table) => $table->dropColumn('push_prefs'));
        Schema::dropIfExists('push_log');
        Schema::dropIfExists('push_subscriptions');
    }
};
