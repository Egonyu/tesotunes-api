<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Event;
use App\Models\EventPromotionRequest;
use App\Models\Role;
use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin moderation queue lists two unrelated tables. Both start their
 * auto-increment at 1, so an id alone cannot say which row is meant.
 *
 * approve() used to look for a Product with that id and fall through to an
 * EventPromotionRequest only when none was found — so with a listing and an
 * event request that share an id, approving the event request published the
 * listing instead, silently, and left the request pending.
 */
class PromotionModerationKindTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator role', 'is_active' => true, 'priority' => 5]
        );

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        return $admin;
    }

    private function draftListing(): Product
    {
        $store = Store::factory()->create(['user_id' => User::factory()->create()->id]);

        return Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'Tiktok Boost',
            'slug' => 'tiktok-boost-'.uniqid(),
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_DRAFT,
            'price_ugx' => 5000,
            'price_credits' => 500,
            'is_active' => false,
        ]);
    }

    private function pendingEventRequest(User $organiser): EventPromotionRequest
    {
        $event = Event::factory()->published()->create([
            'organizer_id' => $organiser->id,
            'user_id' => $organiser->id,
        ]);

        return EventPromotionRequest::create([
            'event_id' => $event->id,
            'requested_by_user_id' => $organiser->id,
            'promotion_title' => 'Kampala Instagram Boost',
            'status' => EventPromotionRequest::STATUS_PENDING,
        ]);
    }

    public function test_approving_an_event_request_does_not_touch_a_listing_with_the_same_id(): void
    {
        $admin = $this->admin();
        $listing = $this->draftListing();
        $eventRequest = $this->pendingEventRequest(User::factory()->create());

        $this->assertSame(
            $listing->id,
            $eventRequest->id,
            'This test is only meaningful while the two ids collide.'
        );

        $this->actingAs($admin)
            ->postJson("/api/admin/promotions/{$eventRequest->id}/approve", ['kind' => 'event_request'])
            ->assertOk()
            ->assertJsonPath('status', EventPromotionRequest::STATUS_ACTIVE);

        $this->assertSame(
            Product::STATUS_DRAFT,
            $listing->fresh()->status,
            'The store listing must be untouched when an event request is approved.'
        );
    }

    public function test_the_queue_labels_which_table_each_row_came_from(): void
    {
        $admin = $this->admin();
        $this->draftListing();
        $this->pendingEventRequest(User::factory()->create());

        $kinds = collect($this->actingAs($admin)->getJson('/api/admin/promotions')->json('data'))
            ->pluck('kind')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['event_request', 'listing'], $kinds);
    }

    public function test_an_unlabelled_request_is_treated_as_a_listing_and_never_guesses(): void
    {
        $admin = $this->admin();
        $eventRequest = $this->pendingEventRequest(User::factory()->create());

        // No listing exists with this id, and no kind was sent. The old code
        // fell through to the event request; it must now 404 rather than act
        // on a row the caller did not name.
        $this->actingAs($admin)
            ->postJson("/api/admin/promotions/{$eventRequest->id}/approve")
            ->assertNotFound();

        $this->assertSame(
            EventPromotionRequest::STATUS_PENDING,
            $eventRequest->fresh()->status
        );
    }
}
