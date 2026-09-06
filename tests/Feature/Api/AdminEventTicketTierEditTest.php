<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Editing an event must not destroy its ticket tiers.
 *
 * The admin update deleted every unsold tier and only afterwards ran the
 * per-tier UPDATE for tiers carrying an id — rows it had just deleted, so those
 * updates matched nothing and the tiers were gone for good. An admin who opened
 * an event and saved it was left with an unbuyable event, and any checkout page
 * still holding the old tier ids got "One or more selected ticket tiers are not
 * available for this event" — a 422 with no hint that an edit had removed them.
 */
class AdminEventTicketTierEditTest extends TestCase
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

    private function tier(Event $event, string $name, int $price): EventTicket
    {
        return EventTicket::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => $name,
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

    public function test_editing_an_event_keeps_its_existing_tiers(): void
    {
        $admin = $this->admin();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $ordinary = $this->tier($event, 'Ordinary', 5000);
        $vip = $this->tier($event, 'VIP', 10000);

        $this->actingAs($admin)->putJson("/api/admin/events/{$event->id}", [
            'title' => 'Teete Experience (edited)',
            'ticket_tiers' => [
                ['id' => $ordinary->id, 'name' => 'Ordinary', 'price' => 5000, 'quantity' => 350],
                ['id' => $vip->id, 'name' => 'VIP', 'price' => 12000, 'quantity' => 100],
            ],
        ])->assertSuccessful();

        $this->assertSame(
            2,
            EventTicket::where('event_id', $event->id)->count(),
            'editing an event must not leave it with no tiers to sell'
        );

        $this->assertDatabaseHas('event_tickets', ['id' => $ordinary->id, 'name' => 'Ordinary']);
        $this->assertDatabaseHas('event_tickets', ['id' => $vip->id, 'price_ugx' => 12000]);
    }

    public function test_tier_ids_survive_an_edit_so_open_checkouts_keep_working(): void
    {
        $admin = $this->admin();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $ordinary = $this->tier($event, 'Ordinary', 5000);

        $this->actingAs($admin)->putJson("/api/admin/events/{$event->id}", [
            'ticket_tiers' => [
                ['id' => $ordinary->id, 'name' => 'Ordinary', 'price' => 5000, 'quantity' => 350],
            ],
        ])->assertSuccessful();

        // A buyer whose checkout page still holds the old id must still resolve it.
        $this->assertNotNull(
            EventTicket::where('event_id', $event->id)->find($ordinary->id),
            'a tier id must survive an edit, or open checkouts 422 on purchase'
        );
    }

    public function test_a_tier_dropped_from_the_payload_is_removed(): void
    {
        $admin = $this->admin();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $ordinary = $this->tier($event, 'Ordinary', 5000);
        $retired = $this->tier($event, 'Retired tier', 3000);

        $this->actingAs($admin)->putJson("/api/admin/events/{$event->id}", [
            'ticket_tiers' => [
                ['id' => $ordinary->id, 'name' => 'Ordinary', 'price' => 5000, 'quantity' => 350],
            ],
        ])->assertSuccessful();

        $this->assertDatabaseHas('event_tickets', ['id' => $ordinary->id]);
        $this->assertDatabaseMissing('event_tickets', ['id' => $retired->id]);
    }

    public function test_a_tier_with_sales_is_never_removed(): void
    {
        $admin = $this->admin();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $sold = $this->tier($event, 'Already sold', 5000);
        $sold->forceFill(['quantity_sold' => 3])->save();

        $this->actingAs($admin)->putJson("/api/admin/events/{$event->id}", [
            'ticket_tiers' => [
                ['name' => 'Brand new tier', 'price' => 7000, 'quantity' => 50],
            ],
        ])->assertSuccessful();

        $this->assertDatabaseHas('event_tickets', ['id' => $sold->id, 'quantity_sold' => 3]);
    }

    public function test_a_tier_added_in_the_form_is_created_despite_its_placeholder_id(): void
    {
        // The admin form labels unsaved rows `new-<timestamp>`. Treating any
        // present id as an existing row sent these down the UPDATE path, where
        // they matched nothing, so tiers an admin added were silently dropped.
        $admin = $this->admin();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $this->actingAs($admin)->putJson("/api/admin/events/{$event->id}", [
            'ticket_tiers' => [
                ['id' => 'new-1757166000000', 'name' => 'Ordinary', 'price' => 5000, 'quantity' => 350],
                ['id' => 'new-1757166000001', 'name' => 'VIP', 'price' => 10000, 'quantity' => 100],
                ['id' => 'new-1757166000002', 'name' => 'Table', 'price' => 100000, 'quantity' => 50],
            ],
        ])->assertSuccessful();

        $this->assertSame(3, EventTicket::where('event_id', $event->id)->count());
        $this->assertDatabaseHas('event_tickets', [
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => 5000,
        ]);
        $this->assertDatabaseHas('event_tickets', [
            'event_id' => $event->id,
            'name' => 'Table',
            'price_ugx' => 100000,
        ]);
    }

    public function test_an_id_belonging_to_another_event_never_updates_it(): void
    {
        $admin = $this->admin();
        $mine = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $theirs = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);
        $theirTier = $this->tier($theirs, 'Their tier', 9000);

        $this->actingAs($admin)->putJson("/api/admin/events/{$mine->id}", [
            'ticket_tiers' => [
                ['id' => $theirTier->id, 'name' => 'Hijacked', 'price' => 1, 'quantity' => 1],
            ],
        ])->assertSuccessful();

        $this->assertDatabaseHas('event_tickets', [
            'id' => $theirTier->id,
            'event_id' => $theirs->id,
            'name' => 'Their tier',
            'price_ugx' => 9000,
        ]);

        // It is not this event's row, so it is treated as a new tier here.
        $this->assertDatabaseHas('event_tickets', [
            'event_id' => $mine->id,
            'name' => 'Hijacked',
        ]);
    }

    public function test_an_edit_that_sends_no_tiers_leaves_them_alone(): void
    {
        $admin = $this->admin();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $this->tier($event, 'Ordinary', 5000);
        $this->tier($event, 'VIP', 10000);

        $this->actingAs($admin)
            ->putJson("/api/admin/events/{$event->id}", ['title' => 'Renamed only'])
            ->assertSuccessful();

        $this->assertSame(2, EventTicket::where('event_id', $event->id)->count());
    }
}
