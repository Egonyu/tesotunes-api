<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('artist_id')->index();
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();

            $table->string('transaction_id')->unique();
            $table->string('payout_method'); // mobile_money, bank_transfer, paypal, zengapay
            $table->string('currency', 3)->default('UGX');

            $table->decimal('amount', 15, 2);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);

            $table->string('status')->default('pending')->index();
            // pending | approved | processing | completed | failed | rejected | cancelled

            // Mobile money / ZengaPay
            $table->string('phone_number')->nullable();

            // Bank transfer
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('account_holder_name')->nullable();

            // Outcome
            $table->string('external_transaction_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            // Lifecycle timestamps
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_payouts');
    }
};
