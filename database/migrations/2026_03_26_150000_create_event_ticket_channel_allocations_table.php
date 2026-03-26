<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_ticket_channel_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('event_tickets')->cascadeOnDelete();
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 50)->default('external');
            $table->string('channel_label')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'ticket_id', 'channel'], 'etca_event_ticket_channel_idx');
            $table->index(['event_id', 'released_at'], 'etca_event_released_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_ticket_channel_allocations');
    }
};
