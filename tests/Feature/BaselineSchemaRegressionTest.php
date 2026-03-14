<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BaselineSchemaRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_baseline_tables_exist(): void
    {
        $tables = [
            'users',
            'user_profiles',
            'user_security_profiles',
            'user_referrals',
            'roles',
            'permissions',
            'user_settings',
            'genres',
            'artists',
            'artist_profiles',
            'albums',
            'songs',
            'song_genres',
            'playlists',
            'playlist_songs',
            'play_histories',
            'payments',
            'payment_issues',
            'subscription_plans',
            'user_subscriptions',
            'activities',
            'feed_items',
            'comments',
            'distributions',
            'notifications',
            'cms_pages',
            'frontend_sections',
            'forum_topics',
            'awards',
            'store_products',
            'loyalty_cards',
            'podcast_listens',
            'podcast_subscriptions',
            'sacco_members',
            'sacco_accounts',
            'sacco_savings_accounts',
            'sacco_savings_transactions',
            'sacco_loan_products',
            'sacco_loans',
            'sacco_loan_repayments',
            'sacco_transactions',
            'sacco_goals',
            'sacco_notifications',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}]");
        }
    }

    public function test_core_baseline_columns_exist(): void
    {
        $expectations = [
            'users' => ['uuid', 'theme_preference', 'referrer_id', 'two_factor_confirmed_at'],
            'user_settings' => ['theme', 'email_notifications', 'sms_notifications'],
            'artists' => ['like_count', 'comments_count', 'verification_status'],
            'artist_profiles' => ['user_id', 'artist_id', 'verification_status', 'verification_documents'],
            'songs' => ['visibility', 'is_streamable', 'comments_count', 'approved_at', 'moderation_reason'],
            'playlists' => ['visibility', 'comments_count'],
            'play_histories' => ['played_at', 'duration_played_seconds', 'playlist_id'],
            'payments' => ['provider_transaction_id', 'payment_provider', 'payment_data'],
            'subscription_plans' => ['price', 'interval', 'limits'],
            'user_subscriptions' => ['subscription_plan_id', 'started_at', 'expires_at', 'auto_renew'],
            'activities' => ['subject_type', 'subject_id', 'comments_count'],
            'comments' => ['replies_count', 'is_pinned'],
            'shares' => ['view_count', 'click_count'],
            'notifications' => ['user_id', 'title', 'message', 'is_read'],
            'cms_pages' => ['slug', 'page_type', 'visibility'],
            'frontend_sections' => ['page', 'content_type', 'display_order'],
            'forum_topics' => ['category_id', 'is_pinned', 'last_activity_at'],
            'awards' => ['year', 'season', 'votes_per_category'],
            'store_products' => ['slug', 'price', 'stock_quantity'],
            'loyalty_cards' => ['artist_id', 'tiers', 'status'],
            'podcast_listens' => ['episode_id', 'listen_duration', 'completed'],
            'podcast_subscriptions' => ['podcast_id', 'notifications_enabled'],
            'sacco_members' => ['member_number', 'membership_tier', 'credit_score', 'loan_access_enabled'],
            'sacco_accounts' => ['account_number', 'account_type', 'balance', 'available_balance'],
            'sacco_savings_accounts' => ['account_number', 'account_type', 'balance_ugx', 'minimum_balance_ugx'],
            'sacco_savings_transactions' => ['transaction_code', 'type', 'amount_ugx', 'balance_after_ugx'],
            'sacco_loan_products' => ['code', 'interest_rate', 'min_repayment_months', 'requires_guarantor'],
            'sacco_loans' => ['application_number', 'principal_amount_ugx', 'principal_amount', 'monthly_repayment'],
            'sacco_loan_repayments' => ['repayment_number', 'amount_due', 'amount_ugx', 'reference'],
            'sacco_transactions' => ['transaction_reference', 'transaction_type', 'amount', 'transaction_date'],
            'sacco_goals' => ['type', 'target_amount', 'current_amount', 'credit_conversion_enabled'],
            'sacco_notifications' => ['member_id', 'type', 'channel', 'read_at'],
        ];

        foreach ($expectations as $table => $columns) {
            $availableColumns = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $availableColumns,
                    "Missing column [{$table}.{$column}]"
                );
            }
        }
    }
}
