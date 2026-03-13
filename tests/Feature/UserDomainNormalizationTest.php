<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserReferral;
use App\Models\UserSecurityProfile;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserDomainNormalizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('user_profiles')
            || ! Schema::hasTable('user_security_profiles')
            || ! Schema::hasTable('user_referrals')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_03_13_160000_create_user_domain_normalization_tables.php',
                '--realpath' => false,
                '--force' => true,
            ]);
        }
    }

    public function test_creating_a_user_creates_normalized_domain_records(): void
    {
        $referralCode = 'TEST-REF-'.uniqid();

        $user = User::factory()->create([
            'display_name' => 'Normalization User',
            'two_factor_enabled' => true,
            'referral_code' => $referralCode,
        ]);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'display_name' => 'Normalization User',
        ]);

        $this->assertDatabaseHas('user_security_profiles', [
            'user_id' => $user->id,
            'two_factor_enabled' => true,
        ]);

        $this->assertDatabaseHas('user_referrals', [
            'user_id' => $user->id,
            'referral_code' => $referralCode,
        ]);
    }

    public function test_create_default_helpers_backfill_existing_user_without_related_records(): void
    {
        $referralCode = 'LEGACY-REF-'.uniqid();

        $user = User::factory()->create([
            'display_name' => 'Legacy User',
            'referral_code' => $referralCode,
        ]);

        UserProfile::where('user_id', $user->id)->delete();
        UserSecurityProfile::where('user_id', $user->id)->delete();
        UserReferral::where('user_id', $user->id)->delete();

        UserProfile::createDefault($user);
        UserSecurityProfile::createDefault($user);
        UserReferral::createDefault($user);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'display_name' => 'Legacy User',
        ]);

        $this->assertDatabaseHas('user_referrals', [
            'user_id' => $user->id,
            'referral_code' => $referralCode,
        ]);
    }

    public function test_user_resource_prefers_loaded_normalized_profile_values(): void
    {
        $user = User::factory()->create([
            'display_name' => 'Legacy Display',
            'bio' => 'Legacy bio',
        ]);

        $user->profile()->update([
            'display_name' => 'Normalized Display',
            'bio' => 'Normalized bio',
        ]);

        $resource = (new UserResource($user->fresh()->load(['profile', 'referralProfile'])))->toArray(request());

        $this->assertSame('Normalized Display', $resource['display_name']);
        $this->assertSame('Normalized bio', $resource['bio']);
    }

    public function test_referral_helpers_prefer_and_sync_normalized_referral_profile(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();

        $referrer->referralProfile()->update([
            'referral_code' => 'PROFILE-REF-'.uniqid(),
        ]);

        $referrer = $referrer->fresh()->load('referralProfile');

        $this->assertStringContainsString($referrer->referral_code, $referrer->referral_link);

        $referrer->recordReferral($referred);

        $this->assertDatabaseHas('user_referrals', [
            'user_id' => $referrer->id,
            'referral_count' => 1,
        ]);

        $this->assertDatabaseHas('user_referrals', [
            'user_id' => $referred->id,
            'referrer_id' => $referrer->id,
        ]);
    }

    public function test_user_preferences_and_credit_reads_prefer_normalized_records(): void
    {
        $user = User::factory()->create([
            'theme_preference' => 'legacy-theme',
            'credits' => 5,
        ]);

        UserSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'theme' => 'normalized-theme',
                'email_notifications' => false,
                'sms_notifications' => true,
            ]
        );

        $user->creditWallet()->updateOrCreate(
            ['user_id' => $user->id],
            ['balance' => 250]
        );

        $user = $user->fresh()->load(['settings', 'creditWallet']);
        $resource = (new UserResource($user))->toArray(request());

        $this->assertSame('normalized-theme', $user->theme_preference);
        $this->assertFalse($user->email_notifications_enabled);
        $this->assertTrue($user->sms_notifications_enabled);
        $this->assertSame(250, $user->credits);
        $this->assertSame('normalized-theme', $resource['theme_preference']);
        $this->assertSame(250, $resource['credits']);
    }

    public function test_two_factor_write_paths_sync_normalized_security_profile(): void
    {
        $user = User::factory()->create([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);

        $user->forceFill([
            'two_factor_secret' => 'secret-key',
            'two_factor_recovery_codes' => json_encode(['code-1', 'code-2']),
        ])->save();

        $user->enableTwoFactor();

        $this->assertDatabaseHas('user_security_profiles', [
            'user_id' => $user->id,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'secret-key',
        ]);

        $user->disableTwoFactor();

        $this->assertDatabaseHas('user_security_profiles', [
            'user_id' => $user->id,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);
    }
}
