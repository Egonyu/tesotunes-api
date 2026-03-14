<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_rates', function (Blueprint $table) {
            $table->id();
            $table->string('activity_type', 50)->unique();
            $table->decimal('credits_per_action', 10, 2);
            $table->decimal('daily_limit', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('payment_type');
            $table->morphs('payable');
            $table->decimal('amount', 15, 2);
            $table->decimal('amount_usd', 15, 2)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();
            $table->decimal('refund_amount', 15, 2)->nullable();
            $table->string('currency', 10)->default('UGX');
            $table->string('phone_number', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('status')->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->string('payment_provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->decimal('fee', 15, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('refund_reason')->nullable();
            $table->json('payment_data')->nullable();
            $table->json('payment_details')->nullable();
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->index(['user_id', 'status']);
            $table->index('provider_transaction_id');
            $table->index('transaction_reference');
            $table->index('created_at');
        });

        Schema::create('payment_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('issue_type', 50)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->string('severity', 20)->default('medium');
            $table->boolean('money_deducted')->default(false);
            $table->boolean('service_delivered')->default(false);
            $table->string('provider_status')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('auto_resolve_attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['issue_type', 'status']);
        });

        Schema::create('user_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 10)->default('credits');
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('source')->nullable();
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->morphs('referenceable');
            $table->timestamps();
            $table->index(['user_id', 'type', 'created_at']);
        });

        Schema::create('artist_revenues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('revenue_type');
            $table->morphs('sourceable');
            $table->decimal('amount_ugx', 15, 2)->default(0);
            $table->decimal('amount_usd', 15, 2)->default(0);
            $table->decimal('platform_fee', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->date('revenue_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['artist_id', 'status', 'revenue_date']);
        });

        Schema::create('royalty_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('collaborator_name')->nullable();
            $table->string('collaborator_email')->nullable();
            $table->string('role')->nullable();
            $table->string('role_description')->nullable();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('split_percentage', 5, 2)->nullable();
            $table->string('split_type', 30)->default('percentage');
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->text('payment_details')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('has_agreed')->default(false);
            $table->timestamp('agreed_at')->nullable();
            $table->text('agreement_signature')->nullable();
            $table->text('notes')->nullable();
            $table->string('recipient_role', 50)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->json('recipient_payout_info')->nullable();
            $table->string('recipient_status', 20)->default('pending');
            $table->boolean('applies_to_streaming')->default(true);
            $table->boolean('applies_to_downloads')->default(true);
            $table->boolean('applies_to_physical')->default(true);
            $table->boolean('applies_to_sync')->default(true);
            $table->boolean('applies_to_performance')->default(true);
            $table->boolean('applies_to_mechanical')->default(true);
            $table->json('territorial_scope')->nullable();
            $table->boolean('worldwide')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->integer('minimum_plays')->default(0);
            $table->decimal('minimum_revenue', 15, 2)->default(0);
            $table->string('agreement_reference')->nullable();
            $table->string('agreement_type', 30)->nullable();
            $table->boolean('tax_withholding_required')->default(false);
            $table->decimal('tax_withholding_rate', 5, 2)->nullable();
            $table->string('tax_form_type', 30)->nullable();
            $table->string('payout_frequency', 20)->default('monthly');
            $table->decimal('minimum_payout_amount', 15, 2)->default(50000);
            $table->boolean('auto_payout_enabled')->default(false);
            $table->timestamp('last_payout_at')->nullable();
            $table->decimal('total_paid_out', 15, 2)->default(0);
            $table->decimal('pending_payout', 15, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['song_id', 'recipient_id']);
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->string('type', 30)->default('standard');
            $table->string('region', 10)->default('EA');
            $table->string('tier');
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->default(0);
            $table->string('currency', 10)->default('UGX');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_usd', 12, 2)->nullable();
            $table->decimal('price_local', 12, 2)->nullable();
            $table->string('interval', 20)->default('month');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('duration_days')->default(30);
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('downloads_per_day')->nullable();
            $table->unsignedInteger('max_downloads_per_day')->nullable();
            $table->unsignedInteger('download_limit')->nullable();
            $table->unsignedInteger('max_uploads_per_month')->nullable();
            $table->unsignedSmallInteger('max_audio_quality_kbps')->default(128);
            $table->boolean('allows_offline')->default(false);
            $table->boolean('ad_free')->default(false);
            $table->string('streaming_quality')->default('128');
            $table->boolean('has_ads')->default(true);
            $table->boolean('offline_mode')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_trial')->default(false);
            $table->boolean('is_popular')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_period')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->string('pause_reason', 500)->nullable();
            $table->dateTime('resumed_at')->nullable();
            $table->dateTime('extended_at')->nullable();
            $table->string('extension_reason', 500)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->string('currency', 10)->default('UGX');
            $table->string('transaction_reference')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->json('metadata')->nullable();
            $table->string('status')->default('active');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('ends_at');
        });

        Schema::create('song_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('currency', 3)->default('UGX');
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->timestamp('purchased_at')->useCurrent();
            $table->timestamps();
            $table->unique(['user_id', 'song_id']);
            $table->index(['user_id', 'purchased_at']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'song_purchases',
            'user_subscriptions',
            'subscription_plans',
            'royalty_splits',
            'artist_revenues',
            'credit_transactions',
            'user_credits',
            'payment_issues',
            'payments',
            'credit_rates',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
