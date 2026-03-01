<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * DB-CRIT-2 fix: Creates podcast_listens table referenced by code
     */
    public function up(): void
    {
        if (! Schema::hasTable('podcast_listens')) {
            Schema::create('podcast_listens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('episode_id')->constrained('podcast_episodes')->cascadeOnDelete();
                $table->integer('position')->default(0)->comment('Playback position in seconds');
                $table->integer('listen_duration')->default(0)->comment('Total listen time in seconds');
                $table->boolean('completed')->default(false);
                $table->string('device_type')->nullable();
                $table->string('country')->nullable();
                $table->timestamp('listened_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'episode_id']);
                $table->index(['episode_id', 'created_at']);
                $table->index('completed');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('podcast_listens');
    }
};
