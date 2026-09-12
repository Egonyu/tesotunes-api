<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guest ticket checkout is off while transactional mail is undeliverable.
 *
 * A guest buys against a throwaway account they can never sign into, so the
 * confirmation email is the only copy of their ticket. With mail failing they
 * would pay and have no route to the QR code they are scanned by at the gate.
 * Signed-in buyers keep working — their ticket lives in the app.
 */
class GuestTicketCheckoutDisabledTest extends TestCase
{
    use RefreshDatabase;

    private function tier(Event $event): EventTicket
    {
        return EventTicket::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => 5000,
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

    private function guestPayload(Event $event, EventTicket $tier): array
    {
        return [
            'event_id' => $event->id,
            'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
            'payment_method' => 'mtn_momo',
            'phone' => '0700123456',
            'holder_name' => 'Walk-in Buyer',
            'holder_email' => 'walkin@example.com',
        ];
    }

    public function test_a_guest_is_asked_to_sign_in_rather_than_charged(): void
    {
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $tier = $this->tier($event);

        $response = $this->postJson('/api/tickets/purchase', $this->guestPayload($event, $tier));

        $response->assertStatus(422);
        $this->assertStringContainsString('sign in', strtolower($response->json('message') ?? ''));
    }

    public function test_a_refused_guest_purchase_issues_no_ticket_and_creates_no_account(): void
    {
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $tier = $this->tier($event);
        $usersBefore = User::query()->count();

        $this->postJson('/api/tickets/purchase', $this->guestPayload($event, $tier))
            ->assertStatus(422);

        $this->assertSame(0, EventAttendee::query()->where('event_id', $event->id)->count());
        $this->assertSame($usersBefore, User::query()->count(), 'no throwaway guest account should be created');
        $this->assertSame(0, (int) $tier->fresh()->quantity_sold);
    }

    public function test_a_signed_in_buyer_is_unaffected(): void
    {
        $buyer = User::factory()->create(['ugx_balance' => 100000]);
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $tier = $this->tier($event);

        $this->withHeaders(['Authorization' => 'Bearer '.$buyer->createToken('t')->plainTextToken])
            ->postJson('/api/tickets/purchase', [
                'event_id' => $event->id,
                'tickets' => [['ticket_tier_id' => $tier->id, 'quantity' => 1]],
                'payment_method' => 'wallet',
            ])
            ->assertStatus(201);
    }

    public function test_turning_the_setting_on_restores_guest_checkout(): void
    {
        // The flag is the whole point: guest checkout comes back without a
        // deploy once mail delivers or order-ID retrieval exists.
        Setting::set('events_guest_checkout_enabled', true);

        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $tier = $this->tier($event);

        $this->postJson('/api/tickets/purchase', $this->guestPayload($event, $tier))
            ->assertStatus(201);
    }
}
