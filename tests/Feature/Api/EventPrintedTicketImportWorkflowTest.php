<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventPrintedTicketImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_import_printed_ticket_codes_and_validation_surfaces_notes(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );

        $organizer = User::factory()->create();
        $organizer->assignRole('artist', $organizer->id);

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Printed Gate',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 10,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $import = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/printed-ticket-imports", [
            'ticket_tier_id' => $ticket->id,
            'codes' => "BOOK-001\nBOOK-002",
            'holder_name' => 'Booklet Buyer',
            'holder_phone' => '0703000001',
            'validation_notes' => 'Orange wristband booklet',
            'notes' => 'Imported from physical booklet A',
        ]);

        $import->assertCreated()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.sale_source', 'printed_ticket')
            ->assertJsonPath('data.validation_notes', 'Orange wristband booklet')
            ->assertJsonPath('data.printed_ticket_import', true);

        $ticket->refresh();
        $this->assertSame(2, $ticket->quantity_sold);

        $lookup = $this->actingAs($organizer)->getJson("/api/artist/events/{$event->id}/check-in/lookup?query=BOOK-001");

        $lookup->assertOk()
            ->assertJsonPath('data.matches.0.ticket_source', 'printed_ticket')
            ->assertJsonPath('data.matches.0.printed_ticket_import', true)
            ->assertJsonPath('data.matches.0.validation_notes', 'Orange wristband booklet');

        $validate = $this->getJson('/api/tickets/validate/BOOK-001');
        $validate->assertOk()
            ->assertJsonPath('data.ticket_source', 'printed_ticket')
            ->assertJsonPath('data.printed_ticket_import', true)
            ->assertJsonPath('data.validation_notes', 'Orange wristband booklet');
    }

    public function test_organizer_can_sync_imported_printed_ticket_batch_details(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );

        $organizer = User::factory()->create();
        $organizer->assignRole('artist', $organizer->id);

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Printed Gate',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 10,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $import = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/printed-ticket-imports", [
            'ticket_tier_id' => $ticket->id,
            'codes' => "SYNC-001\nSYNC-002",
            'holder_name' => 'Original Batch',
            'holder_phone' => '0703000001',
            'validation_notes' => 'Original notes',
        ]);

        $orderId = $import->json('data.order_id');

        $sync = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/printed-ticket-imports/{$orderId}/sync", [
            'holder_name' => 'Updated Batch',
            'holder_email' => 'printed@example.com',
            'holder_phone' => '0703999999',
            'notes' => 'Synced from booklet register',
            'validation_notes' => 'Blue wristband booklet',
        ]);

        $sync->assertOk()
            ->assertJsonPath('data.order_id', $orderId)
            ->assertJsonPath('data.holder_name', 'Updated Batch #1')
            ->assertJsonPath('data.holder_email', 'printed@example.com')
            ->assertJsonPath('data.holder_phone', '0703999999')
            ->assertJsonPath('data.notes', 'Synced from booklet register')
            ->assertJsonPath('data.validation_notes', 'Blue wristband booklet');

        $lookup = $this->actingAs($organizer)->getJson("/api/artist/events/{$event->id}/check-in/lookup?query=SYNC-001");

        $lookup->assertOk()
            ->assertJsonPath('data.matches.0.holder_name', 'Updated Batch #1')
            ->assertJsonPath('data.matches.0.validation_notes', 'Blue wristband booklet');
    }
}
