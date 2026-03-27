<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventPayoutLedgerEntry;
use App\Models\EventTicket;
use App\Models\Artist;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEventActionsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_publish_and_feature_an_event(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $event = Event::factory()->create([
            'status' => 'draft',
            'is_published' => false,
            'is_featured' => false,
        ]);

        $publish = $this->actingAs($admin)->postJson("/api/admin/events/{$event->id}/publish");
        $publish->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_published', true);

        $feature = $this->actingAs($admin)->postJson("/api/admin/events/{$event->id}/toggle-featured");
        $feature->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_featured', true);
    }

    public function test_admin_events_index_includes_risk_summary(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $event = Event::factory()->published()->create([
            'organizer_id' => $admin->id,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Regular',
            'price_ugx' => 30000,
            'price_credits' => 0,
            'quantity_total' => 50,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 5,
            'is_active' => true,
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'EVT-RISK-001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $admin->id,
            'attendee_name' => 'Admin Buyer',
            'attendee_email' => $admin->email,
            'status' => 'confirmed',
            'payment_status' => 'completed',
            'quantity' => 1,
            'amount_paid' => 30000,
            'price_paid_ugx' => 30000,
            'confirmed_at' => now(),
            'attendee_metadata' => [
                'ticket_source' => 'manual_offline',
                'printed_ticket_import' => true,
                'last_check_in_override' => true,
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/events');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.risk.duplicate_check_in_overrides', 1)
            ->assertJsonPath('data.0.risk.manual_offline_ticket_count', 1)
            ->assertJsonPath('data.0.risk.printed_ticket_count', 1);
    }

    public function test_admin_can_view_event_attendees_and_analytics(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $buyer = User::factory()->create();
        $interestedUser = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $admin->id,
            'attendee_count' => 2,
            'marketing_settings' => [
                'campaign_spend' => [
                    [
                        'key' => 'boost-kampala',
                        'label' => 'BOOST-KAMPALA',
                        'amount' => 12000,
                        'currency' => 'UGX',
                    ],
                ],
            ],
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 2,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'EVT-ADMIN-001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Buyer One',
            'attendee_email' => $buyer->email,
            'attendee_phone' => '0700000001',
            'status' => 'confirmed',
            'payment_status' => 'completed',
            'quantity' => 1,
            'amount_paid' => 25000,
            'price_paid_ugx' => 25000,
            'checked_in_at' => now(),
            'confirmed_at' => now(),
            'attendee_metadata' => [
                'order_id' => 'ORDER-ADMIN-001',
                'ticket_source' => 'manual_offline',
                'printed_ticket_import' => true,
                'last_check_in_override' => true,
                'attribution' => [
                    'source' => 'tesotunes_promote',
                    'campaign_code' => 'BOOST-KAMPALA',
                    'utm_campaign' => 'kampala-launch',
                ],
                'fee_breakdown' => [
                    'base_amount' => 50000,
                    'platform_commission_amount' => 3000,
                    'processing_fee_amount' => 2000,
                    'total_fee_amount' => 5000,
                    'total_amount' => 55000,
                    'organizer_net_amount' => 45000,
                ],
            ],
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'EVT-ADMIN-002',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Buyer Two',
            'attendee_email' => $buyer->email,
            'attendee_phone' => '0700000002',
            'status' => 'confirmed',
            'payment_status' => 'completed',
            'quantity' => 1,
            'amount_paid' => 25000,
            'price_paid_ugx' => 25000,
            'confirmed_at' => now(),
            'attendee_metadata' => [
                'order_id' => 'ORDER-ADMIN-001',
                'attribution' => [
                    'source' => 'tesotunes_promote',
                    'campaign_code' => 'BOOST-KAMPALA',
                    'utm_campaign' => 'kampala-launch',
                ],
                'fee_breakdown' => [
                    'base_amount' => 50000,
                    'platform_commission_amount' => 3000,
                    'processing_fee_amount' => 2000,
                    'total_fee_amount' => 5000,
                    'total_amount' => 55000,
                    'organizer_net_amount' => 45000,
                ],
            ],
        ]);

        $interestedUser->interestedEvents()->attach($event->id);

        $attendees = $this->actingAs($admin)->getJson("/api/admin/events/{$event->id}/attendees");
        $attendees->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.ticket_number', 'EVT-ADMIN-001')
            ->assertJsonPath('data.0.ticket.name', 'VIP');

        $analytics = $this->actingAs($admin)->getJson("/api/admin/events/{$event->id}/analytics");
        $analytics->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tickets_sold', 2)
            ->assertJsonPath('data.confirmed_orders', 1)
            ->assertJsonPath('data.interested_count', 1)
            ->assertJsonPath('data.check_ins', 1)
            ->assertJsonPath('data.gross_revenue', 50000)
            ->assertJsonPath('data.customer_paid_total', 55000)
            ->assertJsonPath('data.tesotunes_fee_revenue', 5000)
            ->assertJsonPath('data.estimated_organizer_payout', 45000)
            ->assertJsonPath('data.payouts.ready_balance', 45000)
            ->assertJsonPath('data.fee_contract_coverage.orders_with_fee_breakdown', 1)
            ->assertJsonPath('data.marketing.attributed_orders', 1)
            ->assertJsonPath('data.marketing.top_sources.0.source', 'BOOST-KAMPALA')
            ->assertJsonPath('data.marketing.top_sources.0.gross_revenue', 50000)
            ->assertJsonPath('data.sales_channels.channels.0.key', 'tracked_promo')
            ->assertJsonPath('data.sales_channels.channels.0.orders', 1)
            ->assertJsonPath('data.sales_channels.channels.0.tickets_sold', 2)
            ->assertJsonPath('data.sales_channels.channels.0.gross_revenue', 50000)
            ->assertJsonPath('data.roi.total_spend', 12000)
            ->assertJsonPath('data.roi.by_source.0.label', 'BOOST-KAMPALA')
            ->assertJsonPath('data.roi.by_source.0.spend', 12000)
            ->assertJsonPath('data.roi.by_source.0.net_profit', 33000)
            ->assertJsonPath('data.by_tier.0.name', 'VIP')
            ->assertJsonPath('data.settlements.by_campaign.0.label', 'BOOST-KAMPALA')
            ->assertJsonPath('data.risk.duplicate_check_in_overrides', 1)
            ->assertJsonPath('data.risk.manual_offline_ticket_count', 1)
            ->assertJsonPath('data.risk.printed_ticket_count', 1)
            ->assertJsonPath('data.risk.level', 'low')
            ->assertJsonPath('data.risk.signals.0.key', 'duplicate_check_in_overrides');

        $export = $this->actingAs($admin)->get("/api/admin/events/{$event->id}/analytics/export");
        $export->assertOk();
        $export->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $export->assertSee('Tesotunes Admin Event Payout Export');
        $export->assertSee('ORDER-ADMIN-001');
    }

    public function test_admin_can_create_event_for_selected_organizer(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $organizer = User::factory()->create([
            'name' => 'Event Organizer',
            'email' => 'organizer@example.test',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/events', [
            'title' => 'Organizer-owned Event',
            'start_date' => now()->addWeek()->toDateString(),
            'start_time' => '18:00',
            'organizer_user_id' => $organizer->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organizer.id', $organizer->id)
            ->assertJsonPath('data.organizer_identity.organizer_id', $organizer->id)
            ->assertJsonPath('data.organizer_identity.legacy_user_id', $organizer->id);

        $this->assertDatabaseHas('events', [
            'title' => 'Organizer-owned Event',
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
    }

    public function test_admin_can_create_event_with_performing_artists_contract(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $artistOne = Artist::factory()->create();
        $artistTwo = Artist::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/events', [
            'title' => 'Performer Linked Event',
            'start_date' => now()->addWeek()->toDateString(),
            'start_time' => '20:00',
            'artist_ids' => [$artistOne->id, $artistTwo->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.artist_id', $artistOne->id)
            ->assertJsonCount(2, 'data.artists')
            ->assertJsonPath('data.artists.0.id', (string) $artistOne->id)
            ->assertJsonPath('data.artists.1.id', (string) $artistTwo->id);

        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('event_artist', [
            'event_id' => $eventId,
            'artist_id' => $artistOne->id,
        ]);
        $this->assertDatabaseHas('event_artist', [
            'event_id' => $eventId,
            'artist_id' => $artistTwo->id,
        ]);
    }

    public function test_admin_can_hold_and_release_ready_event_payouts(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        EventPayoutLedgerEntry::create([
            'event_id' => $event->id,
            'organizer_id' => $organizer->id,
            'order_id' => 'ORD-HOLD-1',
            'payment_reference' => 'PAY-HOLD-1',
            'currency' => 'UGX',
            'ticket_quantity' => 2,
            'gross_revenue' => 50000,
            'customer_paid_total' => 55000,
            'tesotunes_fee_revenue' => 5000,
            'platform_commission_amount' => 3000,
            'processing_fee_amount' => 2000,
            'organizer_net_amount' => 45000,
            'fee_source' => 'event_settings',
            'payout_status' => EventPayoutLedgerEntry::STATUS_READY,
            'occurred_at' => now(),
            'payout_ready_at' => now(),
        ]);

        $hold = $this->actingAs($admin)->postJson("/api/admin/events/{$event->id}/payouts/hold", [
            'reason' => 'Compliance review',
        ]);

        $hold->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.held_entries', 1)
            ->assertJsonPath('data.analytics.payouts.held_balance', 45000)
            ->assertJsonPath('data.analytics.payouts.ready_balance', 0)
            ->assertJsonPath('data.analytics.payouts.status_breakdown.held', 1);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_payouts_held',
            'auditable_type' => \App\Models\Event::class,
            'auditable_id' => $event->id,
            'user_id' => $admin->id,
        ]);

        $release = $this->actingAs($admin)->postJson("/api/admin/events/{$event->id}/payouts/release", [
            'note' => 'Approved for payout',
        ]);

        $release->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.released_entries', 1)
            ->assertJsonPath('data.analytics.payouts.held_balance', 0)
            ->assertJsonPath('data.analytics.payouts.ready_balance', 45000);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_payouts_released',
            'auditable_type' => \App\Models\Event::class,
            'auditable_id' => $event->id,
            'user_id' => $admin->id,
        ]);
    }
}
