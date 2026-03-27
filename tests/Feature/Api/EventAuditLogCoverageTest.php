<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventAuditLogCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_campaign_and_discount_changes_are_written_to_event_audit_log(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Artist role', 'is_active' => true, 'priority' => 4]
        );

        $artistUser = User::factory()->create();
        $artistUser->assignRole('artist', $artistUser->id);
        Artist::factory()->create([
            'user_id' => $artistUser->id,
        ]);

        $event = Event::factory()->create([
            'organizer_id' => $artistUser->id,
            'user_id' => $artistUser->id,
            'marketing_settings' => [
                'campaign_spend' => [],
                'campaign_presets' => [],
            ],
        ]);

        $ticket = EventTicket::create([
            'event_id' => $event->id,
            'name' => 'Regular',
            'price_ugx' => 20000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
            'is_free' => false,
        ]);

        $update = $this->actingAs($artistUser)->putJson("/api/artist/events/{$event->id}", [
            'marketing_settings' => [
                'campaign_spend' => [
                    [
                        'key' => 'ig-launch',
                        'label' => 'IG Launch',
                        'amount' => 25000,
                    ],
                ],
                'campaign_presets' => [
                    [
                        'key' => 'wa-blast',
                        'name' => 'WhatsApp Blast',
                        'source' => 'whatsapp',
                        'medium' => 'share',
                        'campaign_code' => 'WA-LAUNCH',
                    ],
                ],
            ],
        ]);

        $update->assertOk();

        $discount = $this->actingAs($artistUser)->postJson("/api/artist/events/{$event->id}/discount-codes", [
            'name' => 'Launch Offer',
            'code' => 'LAUNCH10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'applies_to_ticket_ids' => [$ticket->id],
        ]);

        $discount->assertCreated();
        $discountId = data_get($discount->json(), 'data.discount_codes.0.id');

        $this->actingAs($artistUser)->deleteJson("/api/artist/events/{$event->id}/discount-codes/{$discountId}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_campaign_settings_updated',
            'auditable_type' => \App\Models\Event::class,
            'auditable_id' => $event->id,
            'user_id' => $artistUser->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_discount_code_saved',
            'auditable_type' => \App\Models\Event::class,
            'auditable_id' => $event->id,
            'user_id' => $artistUser->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_discount_code_deleted',
            'auditable_type' => \App\Models\Event::class,
            'auditable_id' => $event->id,
            'user_id' => $artistUser->id,
        ]);
    }
}
