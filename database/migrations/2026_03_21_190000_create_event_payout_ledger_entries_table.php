<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_payout_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('payment_reference', 120)->nullable()->index();
            $table->string('currency', 10)->default('UGX');
            $table->unsignedInteger('ticket_quantity')->default(0);
            $table->decimal('gross_revenue', 14, 2)->default(0);
            $table->decimal('customer_paid_total', 14, 2)->default(0);
            $table->decimal('tesotunes_fee_revenue', 14, 2)->default(0);
            $table->decimal('platform_commission_amount', 14, 2)->default(0);
            $table->decimal('processing_fee_amount', 14, 2)->default(0);
            $table->decimal('organizer_net_amount', 14, 2)->default(0);
            $table->string('fee_source', 80)->nullable();
            $table->string('payout_status', 20)->default('pending')->index();
            $table->string('attribution_label', 150)->nullable();
            $table->json('attribution')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('payout_ready_at')->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id']);
            $table->index(['event_id', 'payout_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_payout_ledger_entries');
    }
};
