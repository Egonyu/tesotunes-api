<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->text('provider_token')->nullable();
            $table->text('provider_refresh_token')->nullable();
            $table->unsignedInteger('profile_completion_percentage')->default(0);
            $table->json('profile_steps_completed')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('phone_verification_code', 10)->nullable();
            $table->timestamp('phone_verification_expires_at')->nullable();
            $table->string('full_name')->nullable();
            $table->string('nin_number')->nullable();
            $table->string('national_id_front_path')->nullable();
            $table->string('national_id_back_path')->nullable();
            $table->string('selfie_with_id_path')->nullable();

            // KYC canonical state — set ONLY via App\Services\Kyc\KycService
            // (see docs/architecture/kyc-3-axis-model.md for the 3-axis model)
            $table->string('kyc_status', 32)->default('none')->index();
            $table->timestamp('kyc_submitted_at')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamp('kyc_expires_at')->nullable();
            $table->text('kyc_rejection_reason')->nullable();

            $table->string('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->string('display_name')->nullable();
            $table->string('stage_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('banner')->nullable();
            $table->string('gender')->nullable();
            $table->string('country')->nullable()->default('Uganda');
            $table->string('city')->nullable();
            $table->string('timezone')->nullable()->default('Africa/Kampala');
            $table->date('date_of_birth')->nullable();
            $table->string('language')->nullable()->default('en');
            $table->string('preferred_language')->nullable();
            $table->string('role')->default('user');
            $table->string('entity_type')->nullable();
            $table->boolean('is_artist')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->string('status')->default('active');
            $table->string('application_status')->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_admin_login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('email_notifications_enabled')->default(true);
            $table->boolean('sms_notifications_enabled')->default(true);
            $table->json('notification_preferences')->nullable();
            $table->json('permissions')->nullable();
            $table->json('settings')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('mobile_money_number')->nullable();
            $table->string('mobile_money_provider')->nullable();
            $table->integer('credits')->default(0);
            $table->decimal('ugx_balance', 15, 2)->default(0);
            $table->string('referral_code')->nullable()->unique();
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->unsignedInteger('referral_count')->default(0);
            $table->timestamp('referred_at')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('theme_preference')->nullable()->default('system');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('role');
            $table->index('status');
            $table->index('is_artist');
            $table->index('is_active');
            $table->index('referrer_id');
            $table->index('referred_by');
            $table->index('last_login_at');
            $table->index(['is_artist', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('referrer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('group')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('email_notifications')->default(true);
            $table->boolean('push_notifications')->default(true);
            $table->boolean('sms_notifications')->default(false);
            $table->string('language')->default('en');
            $table->string('theme')->default('system');
            $table->boolean('autoplay')->default(true);
            $table->string('audio_quality')->default('high');
            $table->boolean('explicit_content')->default(false);
            $table->boolean('show_listening_activity')->default(true);
            $table->boolean('private_profile')->default(false);
            $table->boolean('compact_mode')->default(false);
            $table->string('audio_quality_preference')->nullable();
            $table->string('download_quality')->nullable();
            $table->string('streaming_quality_mobile')->nullable();
            $table->string('streaming_quality_wifi')->nullable();
            $table->boolean('autoplay_enabled')->default(true);
            $table->unsignedTinyInteger('volume_level')->default(100);
            $table->boolean('profile_public')->default(true);
            $table->boolean('show_activity')->default(true);
            $table->boolean('allow_followers')->default(true);
            $table->boolean('allow_messages')->default(true);
            $table->json('notification_preferences')->nullable();
            $table->json('privacy_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->string('banner')->nullable();
            $table->string('gender')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('language')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->unsignedInteger('profile_completion_percentage')->default(0);
            $table->json('profile_steps_completed')->nullable();
            $table->timestamps();
        });

        Schema::create('user_security_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('last_security_reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('referral_code')->nullable()->unique();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('referral_count')->default(0);
            $table->timestamp('referred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('document_number')->nullable();
            $table->string('document_front')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status')->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'document_type']);
            $table->index(['status', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'kyc_documents',
            'user_referrals',
            'user_security_profiles',
            'user_profiles',
            'user_settings',
            'sessions',
            'cache_locks',
            'cache',
            'failed_jobs',
            'jobs',
            'personal_access_tokens',
            'password_reset_tokens',
            'user_roles',
            'role_permissions',
            'permissions',
            'roles',
            'users',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
