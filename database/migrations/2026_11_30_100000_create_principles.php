<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ext_id', 120);                       // the note's id (frontmatter id or file name); re-imports update by this
            $table->string('title', 160);
            $table->text('body');
            $table->string('source', 160)->nullable();
            $table->json('tags');
            $table->string('grade', 20)->default('opinion');      // strong | rule_of_thumb | opinion
            $table->string('stance', 10)->default('context');     // from the note: follow | context | ignore
            $table->string('stance_override', 10)->nullable();    // what YOU set in the app; wins over the note
            $table->string('status', 10)->default('active');      // active | archived (a note deleted from the vault is archived, never hard-deleted)
            $table->string('hash', 40);
            $table->timestamps();
            $table->unique(['user_id', 'ext_id']);
        });
        Schema::table('profiles', function (Blueprint $table) {
            $table->text('coach_stance')->nullable();             // your own few lines: which mentors you follow for what
        });
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $t) => $t->dropColumn('coach_stance'));
        Schema::dropIfExists('principles');
    }
};
