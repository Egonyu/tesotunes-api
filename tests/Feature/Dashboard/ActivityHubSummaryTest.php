<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The account dashboard renders its wallet card from this endpoint. It was
 * filtering store_orders on a `buyer_id` column that does not exist, so the
 * request 500'd for every user and the card fell back to its `?? 0` default —
 * showing UGX 0 and 0 credits to people who had a balance.
 */
class ActivityHubSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_responds_for_a_user_with_no_orders(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/activity-hub/summary')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'wallet' => ['ugx_balance', 'credits'],
                    'promoter' => ['is_promoter'],
                    'pending_actions' => [
                        'buyer_orders_awaiting_review',
                        'seller_orders_to_verify',
                        'open_requests',
                        'pending_applications',
                    ],
                ],
            ]);
    }

    public function test_summary_reports_the_real_wallet_balances(): void
    {
        $user = User::factory()->create();
        $user->ensureCreditWallet()->update(['balance' => 2616]);
        $user->forceFill(['ugx_balance' => 1000])->save();

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/activity-hub/summary')
            ->assertOk()
            ->assertJsonPath('data.wallet.credits', 2616)
            ->assertJsonPath('data.wallet.ugx_balance', 1000);
    }

    public function test_summary_rejects_guests(): void
    {
        $this->getJson('/api/activity-hub/summary')->assertUnauthorized();
    }
}
