<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure the payments table has all columns the application code expects.
 *
 * The base migration (create_base_music_tables) defines `provider`, but the
 * comprehensive_schema_sync migration only adds `payment_provider`.
 * On production the table may have been created without the `provider` column
 * if migration order or partial runs caused a mismatch.
 *
 * This migration is idempotent — it only adds columns that are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'provider')) {
                $table->string('provider', 50)->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('payments', 'provider_transaction_id')) {
                $table->string('provider_transaction_id')->nullable()->after('provider');
            }

            if (! Schema::hasColumn('payments', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('provider_transaction_id');
            }

            if (! Schema::hasColumn('payments', 'phone_number')) {
                $table->string('phone_number', 30)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('payments', 'email')) {
                $table->string('email')->nullable()->after('phone_number');
            }

            if (! Schema::hasColumn('payments', 'description')) {
                $table->string('description')->nullable()->after('email');
            }

            if (! Schema::hasColumn('payments', 'notes')) {
                $table->text('notes')->nullable()->after('description');
            }

            if (! Schema::hasColumn('payments', 'transaction_reference')) {
                $table->string('transaction_reference')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('payments', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('transaction_reference');
            }

            if (! Schema::hasColumn('payments', 'transaction_id')) {
                $table->string('transaction_id')->nullable()->after('payment_reference');
            }

            if (! Schema::hasColumn('payments', 'amount_usd')) {
                $table->decimal('amount_usd', 15, 2)->nullable()->after('amount');
            }

            if (! Schema::hasColumn('payments', 'exchange_rate')) {
                $table->decimal('exchange_rate', 15, 6)->nullable()->after('amount_usd');
            }

            if (! Schema::hasColumn('payments', 'refund_amount')) {
                $table->decimal('refund_amount', 15, 2)->nullable()->after('exchange_rate');
            }

            if (! Schema::hasColumn('payments', 'refund_reason')) {
                $table->text('refund_reason')->nullable();
            }

            if (! Schema::hasColumn('payments', 'payment_data')) {
                $table->json('payment_data')->nullable();
            }

            if (! Schema::hasColumn('payments', 'payment_details')) {
                $table->json('payment_details')->nullable();
            }

            if (! Schema::hasColumn('payments', 'song_id')) {
                $table->unsignedBigInteger('song_id')->nullable();
            }

            if (! Schema::hasColumn('payments', 'subscription_plan_id')) {
                $table->unsignedBigInteger('subscription_plan_id')->nullable();
            }

            if (! Schema::hasColumn('payments', 'initiated_at')) {
                $table->timestamp('initiated_at')->nullable();
            }

            if (! Schema::hasColumn('payments', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }

            if (! Schema::hasColumn('payments', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // No-op: columns should not be removed as they may contain data
    }
};
