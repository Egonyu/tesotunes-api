<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The event planner's fee estimate.
 *
 * The admin create and edit forms posted to this endpoint, but it was never
 * built: /events/{id} claimed the path and answered "The POST method is not
 * supported for route api/admin/events/commission-simulation". The estimator
 * fell back to a local calculation that reports every fee as 0 and net as
 * gross, so an admin planning an event saw revenue figures that ignored the
 * platform's cut entirely.
 */
class AdminEventCommissionSimulationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5]
        );

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        return $admin;
    }

    public function test_the_route_accepts_post_rather_than_matching_the_id_wildcard(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', [
                'ticket_tiers' => [['name' => 'Ordinary', 'price' => 5000, 'quantity' => 10]],
            ])
            ->assertSuccessful();
    }

    public function test_it_returns_the_shape_the_estimator_renders(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', [
                'ticketing_mode' => 'tesotunes_managed',
                'currency' => 'UGX',
                'ticket_tiers' => [
                    ['name' => 'Ordinary', 'price' => 5000, 'quantity' => 350],
                    ['name' => 'VIP', 'price' => 10000, 'quantity' => 100],
                ],
            ])
            ->assertSuccessful();

        $response->assertJsonStructure([
            'data' => [
                'ticketing_mode',
                'mode_label',
                'tesotunes_checkout_enabled',
                'currency',
                'fee_source',
                'platform_commission_percent',
                'processing_fee_percent',
                'totals' => [
                    'ticket_count',
                    'gross_revenue',
                    'customer_paid_total',
                    'tesotunes_fee_revenue',
                    'platform_commission_amount',
                    'processing_fee_amount',
                    'organizer_net_amount',
                ],
                'items' => [['name', 'quantity', 'gross_revenue', 'tesotunes_fee_revenue', 'organizer_net_amount']],
                'scenarios' => [['key', 'label', 'sell_through_percent', 'ticket_count', 'gross_revenue']],
                'upgrade_nudges',
                'notes',
            ],
        ]);

        // 350 * 5000 + 100 * 10000
        $response->assertJsonPath('data.totals.gross_revenue', 2750000);
        $response->assertJsonPath('data.totals.ticket_count', 450);
    }

    public function test_the_organizer_net_is_gross_minus_the_platform_take(): void
    {
        $data = $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', [
                'ticket_tiers' => [['name' => 'Ordinary', 'price' => 10000, 'quantity' => 100]],
            ])
            ->assertSuccessful()
            ->json('data');

        $gross = $data['totals']['gross_revenue'];
        $fee = $data['totals']['tesotunes_fee_revenue'];

        $this->assertSame(1000000.0, (float) $gross);
        $this->assertEqualsWithDelta($gross - $fee, $data['totals']['organizer_net_amount'], 0.01);
        $this->assertEqualsWithDelta($gross + $fee, $data['totals']['customer_paid_total'], 0.01);
        $this->assertEqualsWithDelta(
            $data['totals']['platform_commission_amount'] + $data['totals']['processing_fee_amount'],
            $fee,
            0.01
        );
    }

    public function test_an_externally_ticketed_event_is_charged_nothing(): void
    {
        $data = $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', [
                'ticketing_mode' => 'external_only',
                'ticket_tiers' => [['name' => 'Gate', 'price' => 20000, 'quantity' => 50]],
            ])
            ->assertSuccessful()
            ->json('data');

        $this->assertFalse($data['tesotunes_checkout_enabled']);
        $this->assertSame(0.0, (float) $data['totals']['tesotunes_fee_revenue']);
        $this->assertEqualsWithDelta(
            $data['totals']['gross_revenue'],
            $data['totals']['organizer_net_amount'],
            0.01
        );
    }

    public function test_scenarios_scale_the_projection(): void
    {
        $data = $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', [
                'ticket_tiers' => [['name' => 'Ordinary', 'price' => 1000, 'quantity' => 100]],
            ])
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(4, $data['scenarios']);

        $half = collect($data['scenarios'])->firstWhere('sell_through_percent', 50);
        $this->assertSame(50, $half['ticket_count']);
        $this->assertEqualsWithDelta(
            $data['totals']['gross_revenue'] / 2,
            $half['gross_revenue'],
            0.01
        );
    }

    public function test_tiers_with_no_quantity_are_ignored(): void
    {
        $data = $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', [
                'ticket_tiers' => [
                    ['name' => 'Ordinary', 'price' => 5000, 'quantity' => 10],
                    ['name' => 'Not on sale', 'price' => 9999, 'quantity' => 0],
                ],
            ])
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $data['items']);
        $this->assertSame('Ordinary', $data['items'][0]['name']);
    }

    public function test_it_requires_at_least_one_tier(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/events/commission-simulation', ['ticket_tiers' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ticket_tiers');
    }

    public function test_it_is_closed_to_anonymous_callers(): void
    {
        $this->postJson('/api/admin/events/commission-simulation', [
            'ticket_tiers' => [['name' => 'Ordinary', 'price' => 5000, 'quantity' => 10]],
        ])->assertStatus(401);
    }
}
