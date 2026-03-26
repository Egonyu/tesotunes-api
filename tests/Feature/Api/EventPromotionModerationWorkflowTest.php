<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventPromotionRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPromotionModerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_can_submit_event_promotion_request_and_admin_can_moderate_it(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Artist role', 'is_active' => true, 'priority' => 3]
        );
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator role', 'is_active' => true, 'priority' => 5]
        );

        $artist = User::factory()->create();
        $artist->assignRole('artist', $artist->id);

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $event = Event::factory()->published()->create([
            'organizer_id' => $artist->id,
            'user_id' => $artist->id,
            'title' => 'Tesotunes Rooftop Show',
        ]);

        $submit = $this->actingAs($artist)->postJson("/api/artist/events/{$event->id}/promotion-requests", [
            'promotion_slug' => 'kampala-instagram-boost',
            'promotion_title' => 'Kampala Instagram Boost',
            'promotion_type' => 'social_media_mention',
            'promotion_platform' => 'instagram',
            'price_credits' => 1200,
            'price_ugx' => 250000,
            'request_notes' => 'Push weekend ticket sales for Kampala fans.',
            'payload' => [
                'target_type' => 'event',
                'event_id' => $event->id,
            ],
        ]);

        $submit->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', EventPromotionRequest::STATUS_PENDING)
            ->assertJsonPath('data.promotion_title', 'Kampala Instagram Boost');

        $requestId = $submit->json('data.id');

        $artistEvent = $this->actingAs($artist)->getJson("/api/artist/events/{$event->id}");
        $artistEvent->assertOk()
            ->assertJsonPath('data.promotion_requests.0.promotion_title', 'Kampala Instagram Boost')
            ->assertJsonPath('data.promotion_requests.0.status', EventPromotionRequest::STATUS_PENDING);

        $adminList = $this->actingAs($admin)->getJson('/api/admin/promotions?status=pending');
        $adminList->assertOk()
            ->assertJsonPath('data.0.title', 'Kampala Instagram Boost')
            ->assertJsonPath('data.0.event.title', 'Tesotunes Rooftop Show')
            ->assertJsonPath('data.0.status', EventPromotionRequest::STATUS_PENDING);

        $approve = $this->actingAs($admin)->postJson("/api/admin/promotions/{$requestId}/approve");
        $approve->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', EventPromotionRequest::STATUS_ACTIVE);

        $analytics = $this->actingAs($admin)->getJson('/api/admin/promotions/analytics');
        $analytics->assertOk()
            ->assertJsonPath('data.total_promotions', 1)
            ->assertJsonPath('data.active_promotions', 1)
            ->assertJsonPath('data.total_gmv_ugx', 250000);

        $secondSubmit = $this->actingAs($artist)->postJson("/api/artist/events/{$event->id}/promotion-requests", [
            'promotion_slug' => 'radio-drive-time',
            'promotion_title' => 'Drive Time Radio Push',
            'promotion_type' => 'radio_mention',
            'promotion_platform' => 'radio',
            'price_ugx' => 400000,
        ]);

        $secondSubmit->assertCreated();
        $secondRequestId = $secondSubmit->json('data.id');

        $reject = $this->actingAs($admin)->postJson("/api/admin/promotions/{$secondRequestId}/reject", [
            'reason' => 'Need clearer event schedule and creative assets before approval.',
        ]);

        $reject->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', EventPromotionRequest::STATUS_REJECTED);

        $event->refresh();
        $this->assertCount(2, $event->promotionRequests);
    }
}
