<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── subscription_plans: add columns the model expects but the base migration missed ───
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plans', 'price')) {
                $table->decimal('price', 12, 2)->default(0)->after('currency');
            }
            if (! Schema::hasColumn('subscription_plans', 'price_usd')) {
                $table->decimal('price_usd', 12, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('subscription_plans', 'price_local')) {
                $table->decimal('price_local', 12, 2)->nullable()->after('price_usd');
            }
            if (! Schema::hasColumn('subscription_plans', 'interval')) {
                $table->string('interval', 20)->default('month')->after('price_local');
            }
            if (! Schema::hasColumn('subscription_plans', 'interval_count')) {
                $table->unsignedSmallInteger('interval_count')->default(1)->after('interval');
            }
            if (! Schema::hasColumn('subscription_plans', 'trial_days')) {
                $table->unsignedSmallInteger('trial_days')->default(0)->after('interval_count');
            }
            if (! Schema::hasColumn('subscription_plans', 'duration_days')) {
                $table->unsignedSmallInteger('duration_days')->default(30)->after('trial_days');
            }
            if (! Schema::hasColumn('subscription_plans', 'type')) {
                $table->string('type', 30)->default('standard')->after('description');
            }
            if (! Schema::hasColumn('subscription_plans', 'region')) {
                $table->string('region', 10)->default('EA')->after('type');
            }
            if (! Schema::hasColumn('subscription_plans', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('subscription_plans', 'is_visible')) {
                $table->boolean('is_visible')->default(true)->after('is_featured');
            }
            if (! Schema::hasColumn('subscription_plans', 'is_trial')) {
                $table->boolean('is_trial')->default(false)->after('is_visible');
            }
            if (! Schema::hasColumn('subscription_plans', 'is_popular')) {
                $table->boolean('is_popular')->default(false)->after('is_trial');
            }
            if (! Schema::hasColumn('subscription_plans', 'max_downloads_per_day')) {
                $table->unsignedInteger('max_downloads_per_day')->nullable()->after('downloads_per_day');
            }
            if (! Schema::hasColumn('subscription_plans', 'download_limit')) {
                $table->unsignedInteger('download_limit')->nullable()->after('max_downloads_per_day');
            }
            if (! Schema::hasColumn('subscription_plans', 'max_uploads_per_month')) {
                $table->unsignedInteger('max_uploads_per_month')->nullable()->after('download_limit');
            }
            if (! Schema::hasColumn('subscription_plans', 'max_audio_quality_kbps')) {
                $table->unsignedSmallInteger('max_audio_quality_kbps')->default(128)->after('max_uploads_per_month');
            }
            if (! Schema::hasColumn('subscription_plans', 'allows_offline')) {
                $table->boolean('allows_offline')->default(false)->after('max_audio_quality_kbps');
            }
            if (! Schema::hasColumn('subscription_plans', 'ad_free')) {
                $table->boolean('ad_free')->default(false)->after('allows_offline');
            }
            if (! Schema::hasColumn('subscription_plans', 'limits')) {
                $table->json('limits')->nullable()->after('features');
            }
            if (! Schema::hasColumn('subscription_plans', 'metadata')) {
                $table->json('metadata')->nullable()->after('limits');
            }
        });

        // ─── user_subscriptions: add columns the model expects but the base migration missed ───
        Schema::table('user_subscriptions', function (Blueprint $table) {
            // Backfill subscription_plan_id from plan_id (model uses subscription_plan_id)
            if (! Schema::hasColumn('user_subscriptions', 'subscription_plan_id')) {
                $table->unsignedBigInteger('subscription_plan_id')->nullable()->after('user_id');
                $table->foreign('subscription_plan_id')
                    ->references('id')->on('subscription_plans')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('user_subscriptions', 'payment_id')) {
                $table->unsignedBigInteger('payment_id')->nullable()->after('subscription_plan_id');
            }
            if (! Schema::hasColumn('user_subscriptions', 'started_at')) {
                $table->datetime('started_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('user_subscriptions', 'expires_at')) {
                $table->datetime('expires_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('user_subscriptions', 'cancellation_reason')) {
                $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('user_subscriptions', 'paused_at')) {
                $table->datetime('paused_at')->nullable()->after('cancellation_reason');
            }
            if (! Schema::hasColumn('user_subscriptions', 'pause_reason')) {
                $table->string('pause_reason', 500)->nullable()->after('paused_at');
            }
            if (! Schema::hasColumn('user_subscriptions', 'resumed_at')) {
                $table->datetime('resumed_at')->nullable()->after('pause_reason');
            }
            if (! Schema::hasColumn('user_subscriptions', 'extended_at')) {
                $table->datetime('extended_at')->nullable()->after('resumed_at');
            }
            if (! Schema::hasColumn('user_subscriptions', 'extension_reason')) {
                $table->string('extension_reason', 500)->nullable()->after('extended_at');
            }
            if (! Schema::hasColumn('user_subscriptions', 'payment_method')) {
                $table->string('payment_method', 30)->nullable()->after('extension_reason');
            }
            if (! Schema::hasColumn('user_subscriptions', 'amount_paid')) {
                $table->decimal('amount_paid', 12, 2)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('user_subscriptions', 'currency')) {
                $table->string('currency', 10)->default('UGX')->after('amount_paid');
            }
            if (! Schema::hasColumn('user_subscriptions', 'transaction_reference')) {
                $table->string('transaction_reference')->nullable()->after('currency');
            }
            if (! Schema::hasColumn('user_subscriptions', 'auto_renew')) {
                $table->boolean('auto_renew')->default(true)->after('transaction_reference');
            }
            if (! Schema::hasColumn('user_subscriptions', 'metadata')) {
                $table->json('metadata')->nullable()->after('auto_renew');
            }
        });

        // Backfill subscription_plan_id from plan_id where null
        if (Schema::hasColumn('user_subscriptions', 'plan_id')) {
            DB::statement('UPDATE user_subscriptions SET subscription_plan_id = plan_id WHERE subscription_plan_id IS NULL AND plan_id IS NOT NULL');
        }

        // Backfill expires_at from ends_at where null
        if (Schema::hasColumn('user_subscriptions', 'ends_at')) {
            DB::statement('UPDATE user_subscriptions SET expires_at = ends_at WHERE expires_at IS NULL AND ends_at IS NOT NULL');
        }

        // Backfill started_at from starts_at where null
        if (Schema::hasColumn('user_subscriptions', 'starts_at')) {
            DB::statement('UPDATE user_subscriptions SET started_at = starts_at WHERE started_at IS NULL AND starts_at IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $columnsToRemove = [
                'subscription_plan_id', 'payment_id', 'started_at', 'expires_at',
                'cancellation_reason', 'paused_at', 'pause_reason', 'resumed_at',
                'extended_at', 'extension_reason', 'payment_method', 'amount_paid',
                'currency', 'transaction_reference', 'auto_renew', 'metadata',
            ];

            foreach ($columnsToRemove as $col) {
                if (Schema::hasColumn('user_subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $columnsToRemove = [
                'price', 'price_usd', 'price_local', 'interval', 'interval_count',
                'trial_days', 'duration_days', 'type', 'region', 'is_featured',
                'is_visible', 'is_trial', 'is_popular', 'max_downloads_per_day',
                'download_limit', 'max_uploads_per_month', 'max_audio_quality_kbps',
                'allows_offline', 'ad_free', 'limits', 'metadata',
            ];

            foreach ($columnsToRemove as $col) {
                if (Schema::hasColumn('subscription_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
