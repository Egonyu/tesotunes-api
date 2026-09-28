<?php

namespace Tests\Feature\Payments;

use App\Models\Artist;
use App\Models\ArtistPayout;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payment\MobileMoneyService;
use App\Services\Payment\ZengaPayService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistPayoutApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_reserves_combined_earnings_and_wallet_funds(): void
    {
        Setting::set('payments_minimum_payout_ugx', 1000, Setting::TYPE_INTEGER, Setting::GROUP_PAYMENTS);

        $user = User::factory()->create(['ugx_balance' => 30000]);
        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'earnings_balance' => 10000,
        ]);

        $result = $this->service()->requestPayout(
            $artist,
            32500,
            ArtistPayout::METHOD_MOBILE_MONEY,
            ['phone_number' => '256770000000'],
            $user,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0.0, (float) $artist->fresh()->earnings_balance);
        $this->assertSame(7500.0, (float) $user->fresh()->ugx_balance);

        $payout = ArtistPayout::findOrFail($result['payout_id']);
        $this->assertSame(10000.0, (float) data_get($payout->metadata, 'funding_sources.artist_earnings'));
        $this->assertSame(22500.0, (float) data_get($payout->metadata, 'funding_sources.user_wallet'));
        $this->assertTrue((bool) data_get($payout->metadata, 'funds_reserved'));
    }

    public function test_rejection_releases_reserved_funds_once(): void
    {
        Setting::set('payments_minimum_payout_ugx', 1000, Setting::TYPE_INTEGER, Setting::GROUP_PAYMENTS);

        $user = User::factory()->create(['ugx_balance' => 30000]);
        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'earnings_balance' => 10000,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $service = $this->service();

        $request = $service->requestPayout(
            $artist,
            32500,
            ArtistPayout::METHOD_MOBILE_MONEY,
            ['phone_number' => '256770000000'],
            $user,
        );

        $payout = ArtistPayout::findOrFail($request['payout_id']);
        $service->rejectPayout($payout, $admin, 'Manual review declined');

        $this->assertSame(10000.0, (float) $artist->fresh()->earnings_balance);
        $this->assertSame(30000.0, (float) $user->fresh()->ugx_balance);
        $this->assertFalse((bool) data_get($payout->fresh()->metadata, 'funds_reserved'));
        $this->assertNotNull(data_get($payout->fresh()->metadata, 'funds_released_at'));
    }

    private function service(): PayoutService
    {
        return new PayoutService(
            $this->mock(MobileMoneyService::class),
            $this->mock(ZengaPayService::class),
        );
    }
}
