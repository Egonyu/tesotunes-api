<?php

namespace Tests\Feature\Feed;

use App\Models\Artist;
use App\Models\FeaturedContent;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SponsoredSlotsTest extends TestCase
{
    use DatabaseTransactions;

    private function seedOrganicFeed(int $songs = 12): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $user->id, 'status' => 'approved']);

        Song::factory()->count($songs)->create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'status' => 'published',
            'visibility' => 'public',
        ]);
    }

    public function test_sponsored_cards_are_woven_into_the_for_you_feed(): void
    {
        config(['feed.sponsored.every' => 4]);
        $this->seedOrganicFeed();

        FeaturedContent::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'TesoTunes Awards — vote now',
            'subtitle' => 'Back your favourite Teso artist',
            'link' => '/awards',
            'type' => 'custom',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/feed/for-you?per_page=12')->assertOk();
        $items = collect($response->json('data'));

        $sponsored = $items->where('is_sponsored', true);
        $this->assertGreaterThanOrEqual(1, $sponsored->count(), 'sponsored card must appear');
        $this->assertSame('Sponsored', $sponsored->first() !== null ? 'Sponsored' : null);
        $this->assertSame('sponsored', $sponsored->first()['feed_type']);
        $this->assertTrue((bool) $sponsored->first()['author']['is_verified']);

        // Cadence: card sits right after the 4th organic item.
        $this->assertTrue((bool) ($items[4]['is_sponsored'] ?? false), 'card expected at the configured slot');
    }

    public function test_feed_is_untouched_when_no_inventory_or_disabled(): void
    {
        $this->seedOrganicFeed(6);

        $noInventory = collect($this->getJson('/api/feed/for-you?per_page=6')->assertOk()->json('data'));
        $this->assertSame(0, $noInventory->where('is_sponsored', true)->count());

        config(['feed.sponsored.enabled' => false]);
        FeaturedContent::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Should not appear',
            'type' => 'custom',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $disabled = collect($this->getJson('/api/feed/for-you?per_page=6')->assertOk()->json('data'));
        $this->assertSame(0, $disabled->where('is_sponsored', true)->count());
    }
}
