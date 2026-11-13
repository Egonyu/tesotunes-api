<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-tap reactions on Edula timeline items. Keyed by the same identity the
 * timeline uses for saving and hiding (post:7, song:12, deal:3, feed:{uuid}),
 * so posts and catalogue cards share one mechanism. One reaction per member
 * per item; the set of reactions is operator-defined (general_edula_reactions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edula_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('identity', 100);
            $table->string('reaction', 30);
            $table->timestamps();

            $table->unique(['user_id', 'identity']);
            $table->index(['identity', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edula_reactions');
    }
};
