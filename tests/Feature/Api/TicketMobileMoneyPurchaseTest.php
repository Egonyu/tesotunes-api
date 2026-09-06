<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Replicates the payload the event checkout page posts for a mobile money
 * purchase, so the 422 a buyer sees can be read directly rather than guessed at.
 */
class TicketMobileMoneyPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_money_purchase_mirroring_the_checkout_page_payload(): void
    {
        $organizer = User::factory()->create();
        $buyer = User::factory()->create(['ugx_balance' => 1000]);

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'starts_at' => now()->addWeek(),
        ]);

        $tier = EventTicket::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => 5000,
            'price_credits' => 0,
            'is_free' => false,
            'is_active' => true,
            'quantity_total' => 350,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addWeek(),
        ]);

        $payload = [
            'event_id' => $event->id,
            'tickets' => [
                ['ticket_tier_id' => $tier->id, 'quantity' => 1],
            ],
            'payment_method' => 'mtn_momo',
            'phone' => '0700123456',
            'holder_name' => $buyer->name,
            'holder_email' => $buyer->email,
            'attendee_assignments' => [
                [
                    'ticket_tier_id' => $tier->id,
                    'attendees' => [
                        ['name' => 'Kenzo', 'save_profile' => false],
                    ],
                ],
            ],
            'attribution' => [
                'landing_page' => "/events/{$event->id}",
            ],
        ];

        $this->actingAs($buyer)
            ->postJson('/api/tickets/purchase', $payload)
            ->assertStatus(201);
    }

    public function test_a_checkout_holding_a_removed_tier_is_told_to_refresh(): void
    {
        $buyer = User::factory()->create(['ugx_balance' => 1000]);
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $stale = EventTicket::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => 5000,
            'price_credits' => 0,
            'is_free' => false,
            'is_active' => true,
            'quantity_total' => 10,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addWeek(),
        ]);
        $staleId = $stale->id;
        $stale->delete();

        $this->actingAs($buyer)
            ->postJson('/api/tickets/purchase', [
                'event_id' => $event->id,
                'tickets' => [['ticket_tier_id' => $staleId, 'quantity' => 1]],
                'payment_method' => 'mtn_momo',
                'phone' => '0700123456',
            ])
            ->assertStatus(422);
    }
}
