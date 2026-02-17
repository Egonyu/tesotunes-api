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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Social Authentication
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->text('provider_token')->nullable();
            $table->text('provider_refresh_token')->nullable();

            // Profile Completion
            $table->integer('profile_completion_percentage')->default(0);
            $table->json('profile_steps_completed')->nullable();

            // Phone verification
            $table->string('phone')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            // Identity Verification (Artist KYC)
            $table->string('full_name')->nullable();
            $table->string('nin_number')->nullable();
            $table->string('national_id_front_path')->nullable();
            $table->string('national_id_back_path')->nullable();
            $table->string('selfie_with_id_path')->nullable();

            // Profile
            $table->string('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->string('display_name')->nullable();
            $table->string('stage_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('banner')->nullable();
            $table->string('gender')->nullable();

            // Location
            $table->string('country')->nullable()->default('Uganda');
            $table->string('city')->nullable();
            $table->string('timezone')->nullable()->default('Africa/Kampala');
            $table->date('date_of_birth')->nullable();
            $table->string('language')->nullable()->default('en');

            // User role & status
            $table->string('role')->default('user');
            $table->boolean('is_artist')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->string('status')->default('active');
            $table->string('application_status')->nullable();

            // Online status
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('rejection_reason')->nullable();

            // Activity tracking
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_admin_login_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            // Preferences
            $table->string('preferred_language')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->boolean('email_notifications_enabled')->default(true);
            $table->boolean('sms_notifications_enabled')->default(true);
            $table->json('notification_preferences')->nullable();
            $table->json('permissions')->nullable();
            $table->json('settings')->nullable();

            // Payment info
            $table->string('payment_method')->nullable();
            $table->string('mobile_money_number')->nullable();
            $table->string('mobile_money_provider')->nullable();

            // Credits system
            $table->integer('credits')->default(0);
            $table->decimal('ugx_balance', 15, 2)->default(0);

            // Referral system
            $table->string('referral_code')->nullable()->unique();
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->integer('referral_count')->default(0);
            $table->timestamp('referred_at')->nullable();

            // Social links
            $table->string('instagram_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('tiktok_url')->nullable();

            // Theme preference
            $table->string('theme_preference')->nullable()->default('system');

            // Meta
            $table->unsignedBigInteger('created_by')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('role');
            $table->index('status');
            $table->index('is_artist');
            $table->index('is_active');
            $table->index(['is_artist', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
