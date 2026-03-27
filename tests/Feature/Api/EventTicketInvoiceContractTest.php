<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTicketInvoiceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_ticket_holder_can_fetch_invoice_data(): void
    {
        $organizer = User::factory()->create([
            'name' => 'Nyege Organizers Ltd',
            'email' => 'organizer@example.com',
        ]);
        $buyer = User::factory()->create();

        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'title' => 'Tesotunes Live',
            'venue_name' => 'Lugogo Cricket Oval',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'currency' => 'UGX',
            'contact_info' => [
                'invoice_issuer_name' => 'Tesotunes Events Limited',
                'invoice_support_email' => 'billing@tesotunes.com',
                'support_phone' => '+256700000001',
                'tax_registration_number' => 'TIN-UG-445566',
                'tax_rate_percent' => 18,
                'tax_is_inclusive' => true,
                'tax_vat_notes' => 'VAT included in checkout total.',
            ],
        ]);

        $ticket = EventTicket::create([
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 50000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 1,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
            'is_free' => false,
        ]);

        $attendee = EventAttendee::create([
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Buyer Name',
            'attendee_email' => 'buyer@example.com',
            'attendee_phone' => '+256700000002',
            'price_paid_ugx' => 50000,
            'payment_method' => 'mtn_momo',
            'payment_reference' => 'MOMO-12345',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
            'confirmed_at' => now(),
            'amount_paid' => 50000,
            'attendee_metadata' => [
                'order_id' => 'ORD-TEST123',
                'line_item_fee_breakdown' => [
                    'unit_price_ugx' => 50000,
                    'platform_commission_amount' => 2500,
                    'processing_fee_amount' => 1000,
                    'total_amount' => 53500,
                ],
            ],
        ]);

        $response = $this->actingAs($buyer)->getJson("/api/tickets/{$attendee->id}/invoice");

        $response->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-'.$attendee->confirmation_code)
            ->assertJsonPath('data.event.title', 'Tesotunes Live')
            ->assertJsonPath('data.issuer.name', 'Tesotunes Events Limited')
            ->assertJsonPath('data.issuer.tax_registration_number', 'TIN-UG-445566')
            ->assertJsonPath('data.tax.rate_percent', 18)
            ->assertJsonPath('data.tax.inclusive', true)
            ->assertJsonPath('data.totals.subtotal', 50000)
            ->assertJsonPath('data.totals.service_fee', 3500)
            ->assertJsonPath('data.totals.total_paid', 53500);
    }
}
