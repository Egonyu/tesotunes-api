<?php

use App\Models\Artist;
use App\Models\FeedItem;
use App\Models\Modules\Forum\Poll;
use App\Models\Post;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFeedSetting;
use App\Models\UserFollow;
use App\Modules\Podcast\Models\PodcastEpisode;
use App\Services\TimelineFeedService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.connections.mysql.database'))->toBe('music_test');
    Queue::fake();
    Notification::fake();
    Cache::flush();
    FeedItem::query()->delete();
    Post::query()->delete();
    Song::query()->delete();
    PodcastEpisode::query()->delete();
});

function timelineItem(array $attributes = []): FeedItem
{
    return FeedItem::create(array_merge([
        'uuid' => (string) Str::uuid(), 'type' => 'announcement', 'module' => 'platform',
        'title' => 'A real public update', 'visibility' => 'public', 'published_at' => now(),
        'tags' => [], 'extras' => [], 'actions' => [], 'base_rank_boost' => 10,
    ], $attributes));
}

test('quiet feed is finite and does not manufacture activity', function () {
    $this->getJson('/api/feed/timeline')->assertOk()->assertJsonPath('data', [])
        ->assertJsonPath('meta.recent_count', 0)->assertJsonPath('meta.next_cursor', null);
});

test('older discoveries keep their publication dates and follow fresh content', function () {
    $old = timelineItem(['type' => 'song_release', 'module' => 'music', 'published_at' => now()->subDays(4)]);
    $fresh = timelineItem();
    $response = $this->getJson('/api/feed/timeline')->assertOk();
    expect($response->json('data.0.uuid'))->toBe($fresh->uuid);
    expect($response->json('data.1.uuid'))->toBe($old->uuid);
    expect($response->json('data.1.section'))->toBe('discovery');
    expect($response->json('data.1.published_at'))->toBe($old->published_at->toIso8601String());
});

test('interests are strict including an intentionally empty selection', function () {
    timelineItem(['type' => 'episode_published', 'module' => 'podcasts']);
    timelineItem(['module' => 'promotions', 'type' => 'promotion_request_posted']);
    $this->getJson('/api/feed/timeline?view=interests')->assertOk()->assertJsonPath('data', []);
    $response = $this->getJson('/api/feed/timeline?view=interests&interests[]=podcasts')->assertOk();
    expect(collect($response->json('data'))->pluck('module')->unique()->all())->toBe(['podcasts']);
    $this->getJson('/api/feed/timeline?module=promotions')->assertOk()->assertJsonCount(1, 'data');
});

test('cursor sessions have no duplicates or newly inserted items between pages', function () {
    for ($index = 0; $index < 55; $index++) {
        timelineItem(['published_at' => now()->subMinutes($index + 1)]);
    }
    $page = $this->getJson('/api/feed/timeline?view=latest&per_page=10')->assertOk()->json();
    $keys = collect($page['data'])->pluck('uuid')->all();
    $inserted = timelineItem();
    while ($page['meta']['next_cursor']) {
        $page = $this->getJson('/api/feed/timeline?view=latest&per_page=10&cursor='.urlencode($page['meta']['next_cursor']))->assertOk()->json();
        $keys = array_merge($keys, collect($page['data'])->pluck('uuid')->all());
    }
    expect($keys)->toHaveCount(55);
    expect(array_unique($keys))->toHaveCount(55);
    expect($keys)->not->toContain($inserted->uuid);
});

test('visibility changes are rechecked and cursors cannot cross filters', function () {
    timelineItem(['published_at' => now()->subMinute()]);
    $second = timelineItem(['published_at' => now()->subMinutes(2)]);
    $cursor = $this->getJson('/api/feed/timeline?view=latest&per_page=1')->assertOk()->json('meta.next_cursor');
    $second->update(['visibility' => 'private']);
    $this->getJson('/api/feed/timeline?view=latest&per_page=1&cursor='.urlencode($cursor))->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/feed/timeline?view=interests&cursor='.urlencode($cursor))->assertStatus(409);
    $this->getJson('/api/feed/timeline?cursor=invalid')->assertUnprocessable();
});

test('raw financial activity never becomes a feed card and small public cohorts are suppressed', function () {
    $user = User::factory()->create();
    timelineItem(['type' => 'product_purchased', 'module' => 'store', 'actor_id' => $user->id]);
    timelineItem(['type' => 'loan_taken', 'module' => 'platform', 'visibility' => 'members', 'actor_id' => $user->id]);
    $this->actingAs($user)->getJson('/api/feed/timeline')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pulse', []);
    foreach (User::factory()->count(2)->create() as $other) {
        timelineItem(['type' => 'product_purchased', 'module' => 'store', 'actor_id' => $other->id]);
    }
    $response = $this->getJson('/api/feed/timeline')->assertOk()->assertJsonPath('data', []);
    expect($response->json('pulse'))->toBe([['type' => 'product_purchased', 'count' => 3, 'period' => 'This week']]);
});

test('catalogue fallback excludes unpublished private and unavailable music', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subMonths(3)]);
    Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'private', 'published_at' => now()->subDay()]);
    Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'draft', 'visibility' => 'public']);
    $response = $this->getJson('/api/feed/timeline?module=music')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.uuid'))->toBe('catalog-song-'.$song->id);
    expect($response->json('data.0.section'))->toBe('discovery');
    $this->getJson('/api/feed/timeline?view=latest&module=music')->assertOk()->assertJsonPath('data', []);
});

test('publishing a draft song creates one release feed item', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->create(['artist_id' => $artist->id, 'status' => 'draft', 'visibility' => 'public']);
    $song->update(['status' => 'published', 'published_at' => now()]);
    expect(FeedItem::query()->where('type', 'song_release')->where('subject_id', $song->id)->count())->toBe(1);
    $song->update(['status' => 'draft']);
    $song->update(['status' => 'published']);
    expect(FeedItem::query()->where('type', 'song_release')->where('subject_id', $song->id)->count())->toBe(1);
});

test('module diversity preserves every eligible item', function () {
    $entries = collect([
        ['module' => 'music', 'actor' => 1], ['module' => 'music', 'actor' => 1],
        ['module' => 'podcasts', 'actor' => 2], ['module' => 'store', 'actor' => 3],
    ]);
    $result = app(TimelineFeedService::class)->diversify($entries);
    expect($result)->toHaveCount(4);
    expect($result->get(1)['module'])->toBe('podcasts');
});

test('discovery selection is capped stable during a day and rotates on another day', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-11 12:00:00'));
    for ($index = 0; $index < 20; $index++) {
        timelineItem(['type' => 'song_release', 'module' => 'music', 'published_at' => now()->subDays(7)]);
    }
    $first = $this->getJson('/api/feed/timeline?module=music')->assertOk()->assertJsonCount(8, 'data')->json('data');
    $second = $this->getJson('/api/feed/timeline?module=music')->assertOk()->json('data');
    expect(array_column($first, 'uuid'))->toBe(array_column($second, 'uuid'));
    $this->travel(1)->days();
    $third = $this->getJson('/api/feed/timeline?module=music')->assertOk()->json('data');
    expect(array_column($first, 'uuid'))->not->toBe(array_column($third, 'uuid'));
});

test('legacy catalogue entries retain the real added date instead of being presented as new', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => null, 'created_at' => now()->subYear()]);
    $response = $this->getJson('/api/feed/timeline?module=music')->assertOk();
    expect($response->json('data.0.extras.date_label'))->toBe('Added to catalogue');
    expect($response->json('data.0.published_at'))->toBe($song->created_at->toIso8601String());
    expect($response->json('data.0.section'))->toBe('discovery');
});

test('catalogue fallback does not duplicate a release already represented by an activity', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDays(4)]);
    timelineItem(['type' => 'song_release', 'module' => 'music', 'subject_type' => Song::class, 'subject_id' => $song->id, 'published_at' => $song->published_at]);
    $this->getJson('/api/feed/timeline?module=music')->assertOk()->assertJsonCount(1, 'data');
    $song->updateQuietly(['visibility' => 'private']);
    $this->getJson('/api/feed/timeline?module=music')->assertOk()->assertJsonPath('data', []);
});

test('bearer users are resolved without making the public endpoint require sign in', function () {
    $user = User::factory()->create();
    $post = Post::create(['user_id' => $user->id, 'content' => 'Private owner post', 'visibility' => 'private', 'published_at' => now()]);
    $this->getJson('/api/feed/timeline?module=social')->assertOk()->assertJsonPath('data', []);
    $headers = ['Authorization' => 'Bearer '.$user->createToken('timeline-test')->plainTextToken];
    $this->getJson('/api/feed/timeline?module=social', $headers)->assertOk()->assertJsonPath('data.0.uuid', $post->uuid);
    $this->getJson('/api/feed/timeline?module=social&view=latest', $headers)->assertOk()->assertJsonPath('data', []);
});

test('timeline preferences persist without replacing unrelated user settings', function () {
    $user = User::factory()->create();
    UserFeedSetting::create(['user_id' => $user->id, 'preferences' => ['genres' => ['Ateso']]]);
    $this->actingAs($user)->putJson('/api/feed/timeline/preferences', ['interests' => ['music', 'forum']])
        ->assertOk()->assertJsonPath('data.interests', ['music', 'forum']);
    expect(UserFeedSetting::query()->where('user_id', $user->id)->first()->preferences['genres'])->toBe(['Ateso']);
    $this->getJson('/api/feed/timeline/preferences')->assertOk()->assertJsonPath('data.interests', ['music', 'forum']);
});

test('catalogue items can be saved idempotently without manufacturing feed activity', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subYear()]);
    $identity = 'song:'.$song->id;
    foreach ([1, 2] as $attempt) {
        $this->actingAs($user)->putJson('/api/feed/timeline/preferences', ['action' => 'save', 'identity' => $identity])
            ->assertOk()->assertJsonPath('data.saved', [$identity]);
    }
    $response = $this->getJson('/api/feed/timeline?view=saved')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.identity'))->toBe($identity);
    expect($response->json('data.0.presentation.title'))->toBe($song->title);
    expect(FeedItem::query()->where('subject_type', Song::class)->where('subject_id', $song->id)->count())->toBe(0);
    $song->updateQuietly(['visibility' => 'private']);
    $this->getJson('/api/feed/timeline?view=saved')->assertOk()->assertJsonPath('data', []);
    $this->putJson('/api/feed/timeline/preferences', ['action' => 'unsave', 'identity' => $identity])->assertOk()->assertJsonPath('data.saved', []);
});

test('hiding a song excludes both release and catalogue representations and can be undone', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->createQuietly(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDays(4)]);
    $release = timelineItem(['type' => 'song_release', 'module' => 'music', 'subject_type' => Song::class, 'subject_id' => $song->id]);
    $this->actingAs($user)->putJson('/api/feed/timeline/preferences', ['action' => 'hide', 'identity' => 'feed:'.$release->uuid])
        ->assertOk()->assertJsonPath('data.hidden', ['song:'.$song->id]);
    $this->getJson('/api/feed/timeline?module=music')->assertOk()->assertJsonPath('data', []);
    $this->putJson('/api/feed/timeline/preferences', ['action' => 'restore-hidden'])->assertOk()->assertJsonPath('data.hidden', []);
    $this->getJson('/api/feed/timeline?module=music')->assertOk()->assertJsonCount(1, 'data');
});

test('guests and other users cannot read a personal saved collection', function () {
    $this->getJson('/api/feed/timeline/preferences')->assertUnauthorized();
    $this->getJson('/api/feed/timeline?view=saved')->assertForbidden();
    $this->getJson('/api/feed/timeline?view=following')->assertForbidden();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = timelineItem();
    $this->actingAs($owner)->putJson('/api/feed/timeline/preferences', ['action' => 'save', 'identity' => 'feed:'.$item->uuid])->assertOk();
    $this->actingAs($other)->getJson('/api/feed/timeline?view=saved')->assertOk()->assertJsonPath('data', []);
});

test('preferences cannot save private or financial items', function () {
    $user = User::factory()->create();
    $private = timelineItem(['visibility' => 'private']);
    $financial = timelineItem(['type' => 'loan_taken']);
    foreach ([$private, $financial] as $item) {
        $this->actingAs($user)->putJson('/api/feed/timeline/preferences', ['action' => 'save', 'identity' => 'feed:'.$item->uuid])->assertNotFound();
    }
    $this->getJson('/api/feed/timeline/preferences')->assertOk()->assertJsonPath('data.saved', []);
    $this->putJson('/api/feed/timeline/preferences', ['action' => 'save', 'identity' => 'invalid'])->assertUnprocessable();
});

test('following shows only eligible followed actors rather than global discovery fillers', function () {
    $viewer = User::factory()->create();
    $followed = User::factory()->create();
    $stranger = User::factory()->create();
    timelineItem(['actor_id' => $stranger->id]);
    $expected = timelineItem(['actor_id' => $followed->id]);
    $this->actingAs($viewer)->getJson('/api/feed/timeline?view=following')->assertOk()->assertJsonPath('data', []);
    UserFollow::create(['follower_id' => $viewer->id, 'followable_id' => $followed->id, 'followable_type' => User::class]);
    $this->getJson('/api/feed/timeline?view=following')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $expected->uuid);
});

test('anonymous poll creators are not exposed in timeline presentation', function () {
    $creator = User::factory()->create();
    $poll = Poll::create(['user_id' => $creator->id, 'title' => 'Anonymous community question', 'poll_type' => 'general', 'audience' => 'all', 'status' => 'active', 'is_anonymous' => true]);
    $response = $this->getJson('/api/feed/timeline?module=forum')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.actor.name'))->toBe('Community poll');
    expect($response->json('data.0.actor.id'))->toBeNull();
    expect($response->json('data.0.presentation.poll_id'))->toBe($poll->id);
    expect($response->json('data.0.title'))->toBe($poll->title);
    expect(json_encode($response->json('data')))->not->toContain($creator->name);
    $viewer = User::factory()->create();
    UserFollow::create(['follower_id' => $viewer->id, 'followable_id' => $creator->id, 'followable_type' => User::class]);
    $this->actingAs($viewer)->getJson('/api/feed/timeline?view=following&module=forum')->assertOk()->assertJsonPath('data', []);
});

test('artist follows include public updates published under the artist owner identity', function () {
    $viewer = User::factory()->create();
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $release = timelineItem(['type' => 'album_release', 'module' => 'music', 'actor_id' => $artist->user_id]);
    UserFollow::create(['follower_id' => $viewer->id, 'followable_id' => $artist->id, 'followable_type' => Artist::class]);
    $this->actingAs($viewer)->getJson('/api/feed/timeline?view=following')->assertOk()->assertJsonPath('data.0.uuid', $release->uuid);
});
