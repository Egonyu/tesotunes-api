<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_overview_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/overview')->assertUnauthorized();
    }

    public function test_overview_returns_the_unified_shape(): void
    {
        Sanctum::actingAs(User::factory()->create(['ugx_balance' => 12000]));

        $this->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'wallet' => ['ugx_balance', 'credits_balance'],
                    'earnings' => ['pending', 'available', 'paid_out'],
                    'listening' => ['plays_total', 'plays_30d'],
                    'capabilities',
                    'recent_activity',
                ],
            ])
            ->assertJsonPath('data.wallet.ugx_balance', 12000);
    }

    public function test_contributions_section_is_null_for_a_non_contributor(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.contributions', null);
    }
}
