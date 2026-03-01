<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * DB-CRIT-3 fix: Creates podcast_subscriptions table referenced by model/factory
     */
    public function up(): void
    {
        if (! Schema::hasTable('podcast_subscriptions')) {
            Schema::create('podcast_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('podcast_id')->constrained()->cascadeOnDelete();
                $table->boolean('notifications_enabled')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'podcast_id']);
                $table->index('podcast_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('podcast_subscriptions');
    }
};
