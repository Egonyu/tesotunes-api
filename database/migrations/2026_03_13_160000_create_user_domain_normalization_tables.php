<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_profiles')) {
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
        }

        if (! Schema::hasTable('user_security_profiles')) {
            Schema::create('user_security_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('two_factor_enabled')->default(false);
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('last_security_reviewed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_referrals')) {
            Schema::create('user_referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('referral_code')->nullable()->unique();
                $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('referral_count')->default(0);
                $table->timestamp('referred_at')->nullable();
                $table->timestamps();
            });
        }

        $this->backfillProfiles();
        $this->backfillSecurityProfiles();
        $this->backfillReferrals();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
        Schema::dropIfExists('user_security_profiles');
        Schema::dropIfExists('user_profiles');
    }

    private function backfillProfiles(): void
    {
        if (! Schema::hasTable('user_profiles') || ! Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->select([
                'id',
                'display_name',
                'first_name',
                'last_name',
                'bio',
                'avatar',
                'banner',
                'gender',
                'country',
                'city',
                'timezone',
                'date_of_birth',
                'language',
                'instagram_url',
                'twitter_url',
                'facebook_url',
                'youtube_url',
                'tiktok_url',
                'profile_completion_percentage',
                'profile_steps_completed',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                $rows = [];

                foreach ($users as $user) {
                    if (DB::table('user_profiles')->where('user_id', $user->id)->exists()) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $user->id,
                        'display_name' => $user->display_name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'bio' => $user->bio,
                        'avatar' => $user->avatar,
                        'banner' => $user->banner,
                        'gender' => $user->gender,
                        'country' => $user->country,
                        'city' => $user->city,
                        'timezone' => $user->timezone,
                        'date_of_birth' => $user->date_of_birth,
                        'language' => $user->language,
                        'instagram_url' => $user->instagram_url,
                        'twitter_url' => $user->twitter_url,
                        'facebook_url' => $user->facebook_url,
                        'youtube_url' => $user->youtube_url,
                        'tiktok_url' => $user->tiktok_url,
                        'profile_completion_percentage' => (int) ($user->profile_completion_percentage ?? 0),
                        'profile_steps_completed' => $user->profile_steps_completed,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                }

                if (! empty($rows)) {
                    DB::table('user_profiles')->insert($rows);
                }
            });
    }

    private function backfillSecurityProfiles(): void
    {
        if (! Schema::hasTable('user_security_profiles') || ! Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->select([
                'id',
                'two_factor_enabled',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                $rows = [];

                foreach ($users as $user) {
                    if (DB::table('user_security_profiles')->where('user_id', $user->id)->exists()) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $user->id,
                        'two_factor_enabled' => (bool) $user->two_factor_enabled,
                        'two_factor_secret' => $user->two_factor_secret,
                        'two_factor_recovery_codes' => $user->two_factor_recovery_codes,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                }

                if (! empty($rows)) {
                    DB::table('user_security_profiles')->insert($rows);
                }
            });
    }

    private function backfillReferrals(): void
    {
        if (! Schema::hasTable('user_referrals') || ! Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->select([
                'id',
                'referral_code',
                'referrer_id',
                'referral_count',
                'referred_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                $rows = [];

                foreach ($users as $user) {
                    if (DB::table('user_referrals')->where('user_id', $user->id)->exists()) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $user->id,
                        'referral_code' => $user->referral_code,
                        'referrer_id' => $user->referrer_id,
                        'referral_count' => (int) ($user->referral_count ?? 0),
                        'referred_at' => $user->referred_at,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                }

                if (! empty($rows)) {
                    DB::table('user_referrals')->insert($rows);
                }
            });
    }
};
