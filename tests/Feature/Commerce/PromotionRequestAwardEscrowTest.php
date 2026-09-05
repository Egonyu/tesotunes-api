<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\Song;
use App\Models\User;
use App\Modules\Promotions\Models\PromotionApplication;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Promotions\Services\PromoterOnboardingService;
use App\Modules\Promotions\Services\PromotionRequestService;
use App\Modules\Store\Models\OrderItem;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PromotionRequestAwardEscrowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * @return array{0: User, 1: PromotionRequest, 2: PromotionApplication, 3: User}
     */
    private function buildAwardScenario(int $maxAwards = 1, float $proposedUgx = 30000): array
    {
        $artist = User::factory()->create(['ugx_balance' => 500000, 'credits' => 0]);
        $artistProfile = \App\Models\Artist::factory()->create(['user_id' => $artist->id]);
        $song = Song::factory()->create(['artist_id' => $artistProfile->id, 'user_id' => $artist->id]);

        $promotionRequest = app(PromotionRequestService::class)->createForContent($artist, $song, [
            'title' => 'Push my new single on TikTok',
            'budget_max_ugx' => 50000,
            'max_awards' => $maxAwards,
        ]);

        $promoter = User::factory()->create();
        app(PromoterOnboardingService::class)->onboard($promoter, [
            'display_name' => 'Dubai Diaries',
            'platforms' => ['tiktok'],
        ]);

        $application = app(PromotionRequestService::class)->apply($promotionRequest, $promoter, [
            'proposed_price_ugx' => $proposedUgx,
            'pitch_message' => 'I have 80k followers in the Teso diaspora.',
        ]);

        return [$artist, $promotionRequest, $application, $promoter];
    }

    public function test_award_creates_paid_escrow_order_and_charges_the_artist(): void
    {
        [$artist, $promotionRequest, $application] = $this->buildAwardScenario();

        $result = app(PromotionRequestService::class)->award($promotionRequest, $application, ['payment_method' => 'ugx']);

        $this->assertTrue($result);
        $this->assertSame(470000.0, (float) $artist->fresh()->ugx_balance, 'artist wallet funds the escrow');

        $application->refresh();
        $this->assertSame(PromotionApplication::STATUS_AWARDED, $application->status);
        $this->assertNotNull($application->order_id, 'award must link the escrow order');

        $item = OrderItem::where('order_id', $application->order_id)->firstOrFail();
        $this->assertSame($promotionRequest->id, (int) $item->promotion_request_id);
        $this->assertSame('pending', $item->verification_status);
        $this->assertSame($promotionRequest->promotable_type, $item->promotable_type);

        // No settlement yet — escrow releases on proof verification, not on award.
        $this->assertSame(0, Settlement::query()->where('kind', 'promo_service')->count());
    }

    public function test_award_with_insufficient_wallet_is_rejected_and_nothing_changes(): void
    {
        [$artist, $promotionRequest, $application] = $this->buildAwardScenario(proposedUgx: 30000);
        $artist->forceFill(['ugx_balance' => 1000])->save();

        try {
            app(PromotionRequestService::class)->award($promotionRequest, $application, ['payment_method' => 'ugx']);
            $this->fail('Expected a DomainException for insufficient funds');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
        }

        $this->assertSame(PromotionApplication::STATUS_SUBMITTED, $application->fresh()->status, 'award must roll back');
        $this->assertSame(0, (int) $promotionRequest->fresh()->awarded_count);
    }

    public function test_verified_proof_on_awarded_deal_settles_to_the_promoter(): void
    {
        [, $promotionRequest, $application, $promoter] = $this->buildAwardScenario();

        app(PromotionRequestService::class)->award($promotionRequest, $application, ['payment_method' => 'ugx']);

        $item = OrderItem::where('order_id', $application->fresh()->order_id)->firstOrFail();
        app(PromotionSettlementService::class)->settleOrder($item->order, $item);

        $settlement = Settlement::query()
            ->where('beneficiary_user_id', $promoter->id)
            ->where('kind', 'promo_service')
            ->first();

        $this->assertNotNull($settlement, 'verified deal must settle to the promoter');
        $this->assertEqualsWithDelta(30000.0, (float) $settlement->gross_ugx, 0.001);
        $this->assertSame((int) $item->id, (int) $settlement->source_id);
    }

    public function test_multi_award_keeps_accepting_until_slots_fill_then_rejects_rest(): void
    {
        [, $promotionRequest, $firstApplication] = $this->buildAwardScenario(maxAwards: 2);

        $secondPromoter = User::factory()->create();
        app(PromoterOnboardingService::class)->onboard($secondPromoter, ['display_name' => 'Kampala Vibes']);
        $secondApplication = app(PromotionRequestService::class)->apply($promotionRequest->fresh(), $secondPromoter, [
            'proposed_price_ugx' => 20000,
        ]);

        $thirdPromoter = User::factory()->create();
        app(PromoterOnboardingService::class)->onboard($thirdPromoter, ['display_name' => 'Soroti Sounds']);
        $thirdApplication = app(PromotionRequestService::class)->apply($promotionRequest->fresh(), $thirdPromoter, [
            'proposed_price_ugx' => 25000,
        ]);

        app(PromotionRequestService::class)->award($promotionRequest->fresh(), $firstApplication, ['payment_method' => 'ugx']);

        $this->assertSame(PromotionRequest::STATUS_OPEN, $promotionRequest->fresh()->status, 'stays open with a free slot');
        $this->assertSame(PromotionApplication::STATUS_SUBMITTED, $secondApplication->fresh()->status, 'others not auto-rejected yet');

        app(PromotionRequestService::class)->award($promotionRequest->fresh(), $secondApplication->fresh(), ['payment_method' => 'ugx']);

        $promotionRequest->refresh();
        $this->assertSame(PromotionRequest::STATUS_AWARDED, $promotionRequest->status);
        $this->assertSame(2, (int) $promotionRequest->awarded_count);
        $this->assertSame(PromotionApplication::STATUS_REJECTED, $thirdApplication->fresh()->status, 'rest rejected once full');
        $this->assertSame(PromotionApplication::STATUS_AWARDED, $secondApplication->fresh()->status);
    }

    public function test_v1_promotion_listing_requires_promoter_capability(): void
    {
        $plainUser = User::factory()->create();

        $this->actingAs($plainUser)
            ->postJson('/api/promotions', ['name' => 'My promo package'])
            ->assertForbidden();

        $promoter = User::factory()->create();
        app(PromoterOnboardingService::class)->onboard($promoter, ['display_name' => 'Gated Promoter']);

        // Promoter passes the capability gate (validation errors are fine —
        // the gate is what we assert, not the payload contract).
        $response = $this->actingAs($promoter->fresh())->postJson('/api/promotions', []);
        $this->assertNotSame(403, $response->status());
    }
}
