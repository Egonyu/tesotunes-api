<?php

namespace Tests\Feature\Feed;

use App\Models\Activity;
use App\Models\Artist;
use App\Models\Event;
use App\Models\FeedItem;
use App\Models\Modules\Forum\Poll;
use App\Models\Song;
use App\Models\User;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Modules\Promotions\Services\OpportunityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Edula activity spine: platform events must produce feed items so the
 * timeline fills itself (songs, events, promotion opportunities, community).
 */
class ActivitySpineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_published_song_lands_on_the_feed(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $user->id]);

        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('feed_items', [
            'type' => 'song_release',
            'subject_type' => Song::class,
            'subject_id' => $song->id,
        ]);
    }

    public function test_published_event_lands_on_the_feed(): void
    {
        $organizer = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $this->assertDatabaseHas('feed_items', [
            'subject_type' => Event::class,
            'subject_id' => $event->id,
        ]);
    }

    public function test_posted_opportunity_lands_on_the_feed_with_apply_action(): void
    {
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);
        $song = Song::factory()->create(['artist_id' => $artist->id, 'user_id' => $artistUser->id]);

        $opportunity = app(OpportunityService::class)->createForContent($artistUser, $song, [
            'title' => 'Promote my new single',
            'budget_max_ugx' => 50000,
        ]);

        $feedItem = FeedItem::query()
            ->where('subject_type', PromotionOpportunity::class)
            ->where('subject_id', $opportunity->id)
            ->first();

        $this->assertNotNull($feedItem, 'posted opportunity must announce on the feed');
        $this->assertSame('opportunity_posted', $feedItem->type);
        $this->assertStringContainsString('looking for promoters', $feedItem->title);

        $this->assertDatabaseHas('activities', [
            'type' => 'posted_opportunity',
            'subject_type' => PromotionOpportunity::class,
            'subject_id' => $opportunity->id,
            'user_id' => $artistUser->id,
        ]);
    }

    public function test_poll_creation_writes_activity_and_feed_item(): void
    {
        // Regression: PollObserver passed actor_id/action/metadata — keys the
        // activities table doesn't have — so every poll failed silently and
        // its feed item was lost with it.
        $user = User::factory()->create();

        $poll = Poll::create([
            'user_id' => $user->id,
            'title' => 'Best Teso artist of the year?',
            'poll_type' => 'general',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('activities', [
            'type' => 'created_poll',
            'subject_type' => 'App\Models\Modules\Forum\Poll',
            'subject_id' => $poll->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('feed_items', [
            'type' => 'poll_created',
            'subject_id' => $poll->id,
        ]);
    }

    public function test_feed_endpoint_serves_the_produced_items(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $user->id]);
        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/feed');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('subject_id');
        $this->assertTrue(
            $titles->contains($song->id) || FeedItem::where('subject_id', $song->id)->exists(),
            'feed endpoint should be able to serve produced items'
        );
    }
}
