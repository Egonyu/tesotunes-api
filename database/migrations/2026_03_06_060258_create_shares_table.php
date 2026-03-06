<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shares', function (Blueprint $table) {
            $table->id();

            // Who shared
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What was shared (polymorphic: Song, Album, Playlist, Artist)
            $table->morphs('shareable');

            // Optional message/caption the user added
            $table->text('message')->nullable();

            // Where it was shared
            $table->string('platform')->default('internal')
                ->comment('internal, facebook, twitter, whatsapp, instagram, telegram, copy');

            // Engagement tracking
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'created_at']);
            $table->index(['shareable_type', 'shareable_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};
