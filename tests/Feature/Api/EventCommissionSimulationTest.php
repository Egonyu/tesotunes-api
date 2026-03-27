<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCommissionSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_simulate_commission_for_selected_organizer(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $organizer = User::factory()->create();
        Artist::factory()->create([
            'user_id' => $organizer->id,
            'commission_rate' => 12.5,
        ]);
        SubscriptionPlan::factory()->create([
            'name' => 'Events Pro',
            'slug' => 'events-pro',
            'is_active' => true,
            'is_visible' => true,
            'price_local' => 99000,
            'currency' => 'UGX',
            'metadata' => [
                'event_platform_commission_percent' => 8,
                'event_processing_fee_percent' => 2,
            ],
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/events/commission-simulation', [
            'organizer_user_id' => $organizer->id,
            'ticketing_mode' => 'tesotunes_managed',
            'ticket_tiers' => [
                [
                    'name' => 'VIP',
                    'price' => 50000,
                    'quantity' => 20,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.fee_source', 'artist_commission_rate')
            ->assertJsonPath('data.platform_commission_percent', 12.5)
            ->assertJsonPath('data.totals.ticket_count', 20)
            ->assertJsonPath('data.totals.gross_revenue', 1000000)
            ->assertJsonPath('data.items.0.name', 'VIP')
            ->assertJsonPath('data.scenarios.0.sell_through_percent', 100)
            ->assertJsonPath('data.upgrade_nudges.0.slug', 'events-pro');
    }

    public function test_artist_can_simulate_external_only_mode_without_ticketing_fees(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Artist role', 'is_active' => true, 'priority' => 4]
        );

        $artistUser = User::factory()->create();
        $artistUser->assignRole('artist', $artistUser->id);

        $response = $this->actingAs($artistUser)->postJson('/api/artist/events/commission-simulation', [
            'ticketing_mode' => 'external_only',
            'ticket_tiers' => [
                [
                    'name' => 'Regular',
                    'price' => 30000,
                    'quantity' => 10,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.ticketing_mode', 'external_only')
            ->assertJsonPath('data.tesotunes_checkout_enabled', false)
            ->assertJsonPath('data.fee_source', 'external_only_mode')
            ->assertJsonPath('data.totals.gross_revenue', 300000)
            ->assertJsonPath('data.totals.tesotunes_fee_revenue', 0)
            ->assertJsonPath('data.totals.organizer_net_amount', 300000);
    }
}
