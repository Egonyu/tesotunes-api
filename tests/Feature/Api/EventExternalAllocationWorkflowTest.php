<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventStaffMember;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventExternalAllocationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_reserve_and_release_external_capacity(): void
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
            'ticketing_mode' => Event::TICKETING_MODE_HYBRID,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 35000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 20,
            'quantity_sold' => 2,
            'quantity_reserved' => 1,
            'min_per_order' => 1,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $create = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/external-allocations", [
            'ticket_tier_id' => $ticket->id,
            'quantity' => 5,
            'channel_label' => 'Printed partner outlet',
            'notes' => 'Reserved for mall pickup desk',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.channel', 'external')
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.ticket_tier.name', 'VIP');

        $publicShow = $this->getJson("/api/events/{$event->id}");
        $publicShow->assertOk()
            ->assertJsonPath('data.ticket_tiers.0.quantity_external_allocated', 5)
            ->assertJsonPath('data.ticket_tiers.0.available', 12);

        $allocationId = $create->json('data.id');

        $release = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/external-allocations/{$allocationId}/release", [
            'reason' => 'Partner allocation returned',
        ]);

        $release->assertOk()
            ->assertJsonPath('data.status', 'released');

        $publicShowAfterRelease = $this->getJson("/api/events/{$event->id}");
        $publicShowAfterRelease->assertOk()
            ->assertJsonPath('data.ticket_tiers.0.quantity_external_allocated', 0)
            ->assertJsonPath('data.ticket_tiers.0.available', 17);
    }

    public function test_finance_staff_can_list_external_allocations_but_check_in_staff_cannot_manage_them(): void
    {
        $organizer = User::factory()->create();
        $financeUser = User::factory()->create();
        $checkInUser = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'ticketing_mode' => Event::TICKETING_MODE_HYBRID,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General',
            'price_ugx' => 15000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 40,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        EventStaffMember::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $financeUser->id,
            'invited_by_user_id' => $organizer->id,
            'role' => EventStaffMember::ROLE_FINANCE,
            'is_active' => true,
        ]);

        EventStaffMember::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $checkInUser->id,
            'invited_by_user_id' => $organizer->id,
            'role' => EventStaffMember::ROLE_CHECK_IN,
            'is_active' => true,
        ]);

        $allocation = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/external-allocations", [
            'ticket_tier_id' => $ticket->id,
            'quantity' => 6,
            'channel_label' => 'External partner',
        ]);

        $allocation->assertCreated();

        $list = $this->actingAs($financeUser)->getJson("/api/artist/events/{$event->id}/external-allocations");
        $list->assertOk()
            ->assertJsonPath('data.0.quantity', 6);

        $forbidden = $this->actingAs($checkInUser)->postJson("/api/artist/events/{$event->id}/external-allocations", [
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
            'channel_label' => 'Should fail',
        ]);

        $forbidden->assertForbidden();
    }
}
