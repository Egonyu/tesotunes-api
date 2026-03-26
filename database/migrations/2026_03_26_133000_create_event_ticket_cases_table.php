<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_ticket_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_attendee_id')->constrained('event_attendees')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('case_type', 40);
            $table->string('status', 40)->default('open');
            $table->text('reason');
            $table->text('resolution_notes')->nullable();
            $table->decimal('requested_refund_amount', 12, 2)->nullable();
            $table->decimal('approved_refund_amount', 12, 2)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['event_attendee_id', 'case_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_ticket_cases');
    }
};
