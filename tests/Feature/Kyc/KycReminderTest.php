<?php

namespace Tests\Feature\Kyc;

use App\Enums\KycStatus;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KycReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reminds_users_with_incomplete_kyc(): void
    {
        $incomplete = User::factory()->create([
            'kyc_status' => KycStatus::None->value,
            'created_at' => now()->subDays(5),
        ]);
        $verified = User::factory()->create([
            'kyc_status' => KycStatus::Verified->value,
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('kyc:remind')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $incomplete->id,
            'type' => 'kyc_reminder',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $verified->id,
            'type' => 'kyc_reminder',
        ]);
    }

    public function test_it_skips_brand_new_accounts_within_the_grace_window(): void
    {
        $fresh = User::factory()->create([
            'kyc_status' => KycStatus::None->value,
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('kyc:remind')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $fresh->id,
            'type' => 'kyc_reminder',
        ]);
    }

    public function test_it_does_not_re_remind_within_the_frequency_window(): void
    {
        $user = User::factory()->create([
            'kyc_status' => KycStatus::Partial->value,
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('kyc:remind')->assertSuccessful();
        $this->artisan('kyc:remind')->assertSuccessful();

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'kyc_reminder')
                ->count(),
        );
    }
}
