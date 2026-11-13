<?php

use App\Models\Artist;
use App\Models\FeedItem;
use App\Models\Song;
use App\Models\User;
use App\Modules\Store\Models\Deal;
use App\Modules\Store\Models\Store;
use App\Services\SongService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    Queue::fake();
    Notification::fake();
    Cache::flush();
    FeedItem::query()->delete();
    Deal::query()->delete();
    Song::query()->delete();
});

test('a quiet morning returns an honest, empty card for guests', function () {
    $response = $this->getJson('/api/feed/today')->assertOk();
    expect($response->json('data.date'))->toBe(now()->toDateString());
    expect($response->json('data.town'))->toBeNull();
    expect($response->json('data.tonight'))->toBe([]);
    expect($response->json('data.releases'))->toBe([]);
    expect($response->json('data.deal'))->toBeNull();
    expect($response->json('data.streak'))->toBeNull();
});

test('the card carries the newest release, the home-town deal and the streak', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subHours(2)]);
    Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDays(3)]);

    $kumiStore = Store::factory()->create(['status' => Store::STATUS_ACTIVE, 'city' => 'Kumi']);
    Deal::factory()->create(['store_id' => $kumiStore->id, 'town' => 'Kumi', 'valid_until' => now()->addHours(3)]);
    $sorotiStore = Store::factory()->create(['status' => Store::STATUS_ACTIVE, 'city' => 'Soroti']);
    $sorotiDeal = Deal::factory()->create(['store_id' => $sorotiStore->id, 'town' => 'Soroti', 'valid_until' => now()->addDays(2)]);

    $member = User::factory()->create(['city' => 'Soroti']);
    $response = $this->actingAs($member)->getJson('/api/feed/today')->assertOk();

    expect($response->json('data.town'))->toBe('Soroti');
    expect(collect($response->json('data.releases'))->pluck('song_id')->all())->toBe([$song->id]);
    expect($response->json('data.deal.uuid'))->toBe($sorotiDeal->uuid);
    expect($response->json('data.deal.credits_price'))->toBe(20);
    expect($response->json('data.streak'))->toBe(['days' => 0, 'claimed_today' => false]);
});

test('home-town deals rank ahead of the rest of the region on the timeline', function () {
    $kumiStore = Store::factory()->create(['status' => Store::STATUS_ACTIVE, 'city' => 'Kumi']);
    $sorotiStore = Store::factory()->create(['status' => Store::STATUS_ACTIVE, 'city' => 'Soroti']);
    $kumi = Deal::factory()->create(['store_id' => $kumiStore->id, 'town' => 'Kumi', 'status' => Deal::STATUS_DRAFT, 'published_at' => null]);
    $soroti = Deal::factory()->create(['store_id' => $sorotiStore->id, 'town' => 'Soroti', 'status' => Deal::STATUS_DRAFT, 'published_at' => null]);
    $service = app(\App\Modules\Store\Services\DealService::class);
    $service->publish($soroti);
    $service->publish($kumi);
    FeedItem::query()->where('subject_id', $kumi->id)->update(['published_at' => now()]);
    FeedItem::query()->where('subject_id', $soroti->id)->update(['published_at' => now()->subHours(6)]);

    $member = User::factory()->create(['city' => 'Soroti']);
    $response = $this->actingAs($member)->getJson('/api/feed/timeline?module=store')->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.0.presentation.deal_uuid'))->toBe($soroti->uuid);
});

test('completed plays feed the anonymous pulse but never become cards', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'is_free' => true, 'published_at' => now()->subDay()]);
    $listeners = User::factory()->count(3)->create();
    foreach ($listeners as $listener) {
        app(SongService::class)->recordPlay($song, $listener, ['duration_played_seconds' => 200, 'completed' => true]);
    }

    expect(FeedItem::query()->where('type', 'song_played')->count())->toBe(3);
    $response = $this->getJson('/api/feed/timeline?module=music&view=latest')->assertOk();
    expect(collect($response->json('data'))->pluck('type')->all())->not->toContain('song_played');
    expect(collect($response->json('pulse'))->firstWhere('type', 'song_played'))->toBe(['type' => 'song_played', 'count' => 3, 'period' => 'This week']);
});

test('setting a home town on the profile reaches the morning card, and clearing it removes it', function () {
    $member = User::factory()->create(['city' => null]);
    $this->actingAs($member)->putJson('/api/user', ['city' => 'Kumi'])->assertOk();
    expect($member->fresh()->city)->toBe('Kumi');
    $this->actingAs($member->fresh())->getJson('/api/feed/today')->assertOk()->assertJsonPath('data.town', 'Kumi');

    $this->actingAs($member->fresh())->putJson('/api/user', ['city' => ''])->assertOk();
    expect($member->fresh()->city)->toBeNull();
    $this->actingAs($member->fresh())->putJson('/api/user', ['city' => str_repeat('x', 81)])->assertUnprocessable();
});
