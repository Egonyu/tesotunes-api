<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Artist;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventsApiStandardizationTest extends TestCase
{
    use RefreshDatabase;

    private User $artistUser;

    private User $adminUser;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
        );

        $this->artistUser = User::factory()->create();
        $this->artistUser->assignRole('artist', $this->artistUser->id);

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin', $this->adminUser->id);

        $artist = Artist::factory()->verified()->create([
            'user_id' => $this->artistUser->id,
            'status' => 'active',
        ]);

        $this->event = Event::factory()->published()->create([
            'organizer_id' => $this->artistUser->id,
            'user_id' => $this->artistUser->id,
            'artist_id' => $artist->id,
            'title' => 'Standardized Event',
            'category' => 'music',
            'ticketing_mode' => Event::TICKETING_MODE_HYBRID,
            'venue_name' => 'National Theatre',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'attendee_count' => 1,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_ugx' => 20000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'STD-EVT-001',
            'event_id' => $this->event->id,
            'ticket_id' => $ticket->id,
            'user_id' => User::factory()->create()->id,
            'attendee_name' => 'Standard Guest',
            'attendee_email' => 'guest@example.com',
            'status' => 'confirmed',
            'payment_status' => 'completed',
            'quantity' => 1,
            'amount_paid' => 20000,
            'price_paid_ugx' => 20000,
            'confirmed_at' => now(),
        ]);
    }

    public function test_public_events_index_returns_standard_paginated_resource_shape(): void
    {
        $response = $this->getJson('/api/events');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'slug', 'status', 'starts_at', 'venue_name', 'city', 'ticketing_mode', 'ticketing_summary', 'artists', 'waitlist_count', 'waitlist_joined', 'links'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);

        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_public_event_detail_returns_data_wrapper_with_ticket_tiers(): void
    {
        $response = $this->getJson("/api/events/{$this->event->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'slug',
                    'ticketing_mode',
                    'artists',
                    'ticketing_summary' => ['mode_label', 'tesotunes_checkout_enabled', 'manual_reconciliation_enabled', 'external_allocated'],
                    'waitlist_count',
                    'waitlist_joined',
                    'ticket_tiers' => [
                        '*' => ['id', 'name', 'price', 'price_credits', 'available', 'max_per_order'],
                    ],
                ],
            ]);

        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_public_event_categories_return_plain_data_wrapper(): void
    {
        $response = $this->getJson('/api/events/categories');

        $response->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_artist_events_index_returns_standard_paginated_resource_shape(): void
    {
        $response = $this->actingAs($this->artistUser)->getJson('/api/artist/events');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'slug', 'status', 'starts_at', 'ticketing_mode', 'ticketing_summary', 'artists', 'waitlist_count', 'waitlist_joined', 'links'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);

        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_public_event_detail_includes_explicit_ticketing_mode_value(): void
    {
        $response = $this->getJson("/api/events/{$this->event->id}");

        $response->assertOk()
            ->assertJsonPath('data.ticketing_mode', Event::TICKETING_MODE_HYBRID);
    }

    public function test_public_event_detail_includes_ticketing_summary_for_hybrid_mode(): void
    {
        $response = $this->getJson("/api/events/{$this->event->id}");

        $response->assertOk()
            ->assertJsonPath('data.ticketing_summary.mode_label', 'Tesotunes + external channels')
            ->assertJsonPath('data.ticketing_summary.tesotunes_checkout_enabled', true)
            ->assertJsonPath('data.ticketing_summary.manual_reconciliation_enabled', true)
            ->assertJsonPath('data.ticketing_summary.tesotunes_sold', 1);
    }

    public function test_artist_event_analytics_returns_data_wrapper_without_success_key(): void
    {
        $response = $this->actingAs($this->artistUser)->getJson("/api/artist/events/{$this->event->id}/analytics");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['tickets_sold', 'revenue', 'revenue_credits', 'check_ins', 'by_tier', 'by_date'],
            ]);

        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_admin_events_index_returns_success_data_and_meta(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson('/api/admin/events');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_admin_event_attendees_returns_success_data_meta_and_links(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson("/api/admin/events/{$this->event->id}/attendees");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'ticket_number', 'status', 'payment_status', 'attendee', 'ticket'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ])
            ->assertJsonPath('success', true);
    }
}
