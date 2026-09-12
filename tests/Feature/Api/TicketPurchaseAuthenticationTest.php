<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A signed-in buyer must be recognised on the public purchase route.
 *
 * /api/tickets/purchase carries no auth:sanctum middleware so that guests can
 * buy. Nothing then switched the auth manager to the sanctum guard, so
 * auth()->user() used the default `web` session guard and returned null for a
 * token-bearing API request. Every signed-in buyer was treated as a guest:
 * asked for a name and email, refused wallet and credit payment, and — had it
 * gone through — issued the ticket to a throwaway guest account.
 *
 * These tests authenticate with a real bearer token rather than actingAs(),
 * which sets the guard directly and hides the fault entirely.
 */
class TicketPurchaseAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function tier(Event $event, int $price = 5000): EventTicket
    {
        return EventTicket::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => $price,
            'price_credits' => 0,
            'is_free' => false,
            'is_active' => true,
            'quantity_total' => 100,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addWeek(),
        ]);
    }

    private function event(): Event
    {
        return Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_a_token_bearing_buyer_is_not_asked_for_guest_details(): void
    {
        $buyer = User::factory()->create(['ugx_balance' => 100000]);
        $event = $this->event();
        $tier = $this->tier($event);

        $response = $this->withHeaders($this->bearer($buyer))->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
            'payment_method' => 'mtn_momo',
            'phone' => '0700123456',
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString('Guest checkout requires', $response->getContent());
    }

    public function test_the_ticket_belongs_to_the_signed_in_buyer_not_a_guest_account(): void
    {
        $buyer = User::factory()->create(['ugx_balance' => 100000]);
        $event = $this->event();
        $tier = $this->tier($event);

        $this->withHeaders($this->bearer($buyer))->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
            'payment_method' => 'mtn_momo',
            'phone' => '0700123456',
        ])->assertStatus(201);

        $attendee = EventAttendee::query()->where('event_id', $event->id)->firstOrFail();

        $this->assertSame($buyer->id, $attendee->user_id, 'the ticket must be issued to the buyer');
        $this->assertDatabaseMissing('users', ['username' => 'guest_%']);
    }

    public function test_a_signed_in_buyer_can_pay_from_their_wallet(): void
    {
        // Wallet payment is refused for guests, so this only passes if the
        // bearer token is actually resolved.
        $buyer = User::factory()->create(['ugx_balance' => 100000]);
        $event = $this->event();
        $tier = $this->tier($event);

        $this->withHeaders($this->bearer($buyer))->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ])->assertStatus(201);
    }

    public function test_a_genuine_guest_is_still_asked_for_a_name_and_email(): void
    {
        // With guest checkout disabled every guest is refused, which would make
        // this pass for the wrong reason. Switch it on so the assertion is
        // genuinely about the missing contact details.
        \App\Models\Setting::set('events_guest_checkout_enabled', true);

        $event = $this->event();
        $tier = $this->tier($event);

        $response = $this->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
            'payment_method' => 'mtn_momo',
            'phone' => '0700123456',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('name and email', strtolower($response->json('message') ?? ''));
    }

    public function test_a_genuine_guest_can_still_buy_with_contact_details(): void
    {
        // Guest checkout ships disabled while transactional mail is
        // undeliverable; this covers the mechanism itself, with it switched on.
        \App\Models\Setting::set('events_guest_checkout_enabled', true);

        $event = $this->event();
        $tier = $this->tier($event);

        $this->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
            'payment_method' => 'mtn_momo',
            'phone' => '0700123456',
            'holder_name' => 'Walk-in Buyer',
            'holder_email' => 'walkin@example.com',
        ])->assertStatus(201);
    }
}
