<?php

namespace Tests\Feature\Api;

use App\Models\Commerce\Settlement;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ticket fees, schedules and tiers — September 2026 fixes.
 *
 * - Fees were charged twice: added to the buyer's total and deducted from the
 *   organiser's settlement (25.8% of a ticket at default rates).
 * - Organisers typed local times that were stored as UTC (a 7 PM show showed
 *   as 10 PM to buyers in Uganda).
 * - Artist tier edits were discarded, and moving an event left its tiers'
 *   sales closed at the old date ("Ordinary is not currently available").
 */
class EventFeeHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function organizer(): User
    {
        Role::query()->firstOrCreate(
            ['name' => 'user'],
            ['display_name' => 'User', 'description' => 'Standard user', 'is_active' => true, 'priority' => 1]
        );

        $organizer = User::factory()->create();
        $organizer->assignRole('user', $organizer->id);
        $organizer->syncEventOrganizerProfile(['enabled' => true, 'business_name' => 'Teso Nights']);

        return $organizer;
    }

    private function eventWithTier(User $organizer, string $feeHandling, float $price = 5000): array
    {
        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'fee_handling' => $feeHandling,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(6),
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => $price,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        return [$event, $ticket];
    }

    private function buyWithWallet(Event $event, EventTicket $ticket): array
    {
        $buyer = User::factory()->create(['ugx_balance' => 100000]);

        return $this->actingAs($buyer)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 1,
            'payment_method' => 'wallet',
            'holder_name' => 'Buyer',
            'holder_email' => $buyer->email,
        ])->assertCreated()->json('data');
    }

    public function test_fees_passed_to_the_buyer_are_not_also_taken_from_the_organiser(): void
    {
        $organizer = $this->organizer();
        [$event, $ticket] = $this->eventWithTier($organizer, Event::FEE_HANDLING_PASS_TO_BUYER);

        $order = $this->buyWithWallet($event, $ticket);

        $this->assertEqualsWithDelta(5645, $order['total_amount'], 0.01, 'Buyer pays price + 12.9% fees.');

        $settlement = Settlement::query()->where('beneficiary_user_id', $organizer->id)->firstOrFail();
        $this->assertEqualsWithDelta(5000, (float) $settlement->gross_ugx, 0.01);
        $this->assertEqualsWithDelta(0, (float) $settlement->fee_ugx, 0.01);
        $this->assertEqualsWithDelta(5000, (float) $settlement->net_ugx, 0.01);
    }

    public function test_fees_absorbed_by_the_organiser_leave_the_buyer_paying_the_listed_price(): void
    {
        $organizer = $this->organizer();
        [$event, $ticket] = $this->eventWithTier($organizer, Event::FEE_HANDLING_ABSORB);

        $order = $this->buyWithWallet($event, $ticket);

        $this->assertEqualsWithDelta(5000, $order['total_amount'], 0.01, 'Buyer pays exactly the listed price.');

        $settlement = Settlement::query()->where('beneficiary_user_id', $organizer->id)->firstOrFail();
        $this->assertEqualsWithDelta(645, (float) $settlement->fee_ugx, 0.01);
        $this->assertEqualsWithDelta(4355, (float) $settlement->net_ugx, 0.01);
    }

    public function test_event_pages_show_each_tiers_price_including_fees(): void
    {
        $organizer = $this->organizer();
        [$passEvent] = $this->eventWithTier($organizer, Event::FEE_HANDLING_PASS_TO_BUYER);
        [$absorbEvent] = $this->eventWithTier($organizer, Event::FEE_HANDLING_ABSORB);

        $this->getJson("/api/events/{$passEvent->id}")
            ->assertOk()
            ->assertJsonPath('data.fee_handling', 'pass_to_buyer')
            ->assertJsonPath('data.ticket_tiers.0.buyer_price_ugx', 5645);

        $this->getJson("/api/events/{$absorbEvent->id}")
            ->assertOk()
            ->assertJsonPath('data.ticket_tiers.0.buyer_price_ugx', 5000)
            ->assertJsonPath('data.ticket_tiers.0.fees_included_in_price', true);
    }

    public function test_local_start_time_is_stored_in_the_events_timezone(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($organizer)->postJson('/api/artist/events', [
            'title' => 'Teete Experience',
            'status' => 'draft',
            'start_date' => '2026-12-16',
            'start_time' => '19:00',
            'venue_name' => 'Flame Club',
            'city' => 'Soroti',
            'fee_handling' => 'absorb',
        ])->assertCreated();

        $event = Event::query()->where('title', 'Teete Experience')->firstOrFail();

        // 7 PM in Kampala (UTC+3) is 4 PM UTC.
        $this->assertSame('2026-12-16 16:00:00', $event->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('absorb', $event->fee_handling);
    }

    public function test_artist_tier_edits_are_saved_and_sold_tiers_are_protected(): void
    {
        $organizer = $this->organizer();
        [$event, $ticket] = $this->eventWithTier($organizer, Event::FEE_HANDLING_PASS_TO_BUYER);
        $ticket->update(['quantity_sold' => 5]);

        $this->actingAs($organizer)->putJson("/api/artist/events/{$event->id}", [
            'ticket_tiers' => [
                ['id' => $ticket->id, 'name' => 'Ordinary', 'price' => 7000, 'quantity' => 80, 'max_per_order' => 4],
                ['name' => 'VIP', 'price' => 20000, 'quantity' => 20],
            ],
        ])->assertOk();

        $ticket->refresh();
        $this->assertEqualsWithDelta(7000, (float) $ticket->price_ugx, 0.01, 'Artist tier edits used to be discarded.');
        $this->assertSame(80, $ticket->quantity_total);
        $this->assertSame(2, $event->tickets()->count());

        $this->actingAs($organizer)->putJson("/api/artist/events/{$event->id}", [
            'ticket_tiers' => [['id' => $ticket->id, 'name' => 'Ordinary', 'price' => 7000, 'quantity' => 3]],
        ])->assertStatus(422)->assertJsonValidationErrors('ticket_tiers.0.quantity');

        // A tier left out of the payload is removed only if it never sold.
        $this->actingAs($organizer)->putJson("/api/artist/events/{$event->id}", [
            'ticket_tiers' => [['name' => 'Table', 'price' => 100000, 'quantity' => 5]],
        ])->assertOk();

        $this->assertNotNull(EventTicket::find($ticket->id), 'A tier with sales must never be deleted.');
        $this->assertNull(EventTicket::where('name', 'VIP')->first());
    }

    public function test_moving_an_event_carries_tier_sales_that_ended_at_the_old_start(): void
    {
        $organizer = $this->organizer();
        [$event, $ticket] = $this->eventWithTier($organizer, Event::FEE_HANDLING_PASS_TO_BUYER);

        $oldStart = Carbon::parse('2026-09-14 16:00:00', 'UTC');
        $event->update(['starts_at' => $oldStart]);
        $ticket->update(['sale_ends_at' => $oldStart]);

        $custom = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Early bird',
            'price_ugx' => 3000,
            'quantity_total' => 10,
            'max_per_order' => 2,
            'is_active' => true,
            'sale_ends_at' => Carbon::parse('2026-09-10 12:00:00', 'UTC'),
        ]);

        $newStart = Carbon::parse('2026-09-16 16:00:00', 'UTC');
        $event->update(['starts_at' => $newStart]);

        $this->assertTrue($ticket->fresh()->sale_ends_at->equalTo($newStart));
        $this->assertSame('2026-09-10 12:00:00', $custom->fresh()->sale_ends_at->utc()->format('Y-m-d H:i:s'), 'A deliberate sale end stays put.');
    }

    public function test_checkout_says_why_a_tier_cannot_be_bought(): void
    {
        $organizer = $this->organizer();
        [$event, $ticket] = $this->eventWithTier($organizer, Event::FEE_HANDLING_PASS_TO_BUYER);
        $ticket->update(['sale_ends_at' => now()->subDay()]);

        $buyer = User::factory()->create(['ugx_balance' => 100000]);

        $response = $this->actingAs($buyer)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 1,
            'payment_method' => 'wallet',
            'holder_name' => 'Buyer',
            'holder_email' => $buyer->email,
        ]);

        $this->assertStringContainsString('Sales ended', json_encode($response->json()));
    }

    public function test_admin_event_stats_count_tickets_actually_sold(): void
    {
        $organizer = $this->organizer();
        [$event, $ticket] = $this->eventWithTier($organizer, Event::FEE_HANDLING_PASS_TO_BUYER);
        $this->buyWithWallet($event, $ticket);
        \Illuminate\Support\Facades\Cache::flush();

        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5]
        );
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);
        // Roles are cached per user id, and ids repeat across refreshed tests.
        cache()->forget("user:{$admin->id}:roles");

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/events/stats')
            ->assertOk()
            ->assertJsonPath('data.upcoming_count', 1)
            ->assertJsonPath('data.tickets_sold_30d', 1);

        // Found by id: suites that commit rows can put other events first.
        $listed = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/events?per_page=100')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertNotNull($listed);
        $this->assertSame(1, $listed['tickets_sold']);
        $this->assertSame(100, $listed['tickets_capacity']);
    }

    public function test_artists_get_a_real_fee_estimate(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($organizer)->postJson('/api/artist/events/commission-simulation', [
            'fee_handling' => 'absorb',
            'ticket_tiers' => [['name' => 'Ordinary', 'price' => 5000, 'quantity' => 100]],
        ])
            ->assertOk()
            ->assertJsonPath('data.fee_handling', 'absorb')
            ->assertJsonPath('data.totals.customer_paid_total', 500000)
            ->assertJsonPath('data.totals.organizer_net_amount', 435500);
    }
}
