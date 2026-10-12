<?php

use App\Models\Artist;
use App\Models\Event;
use App\Models\FeedItem;
use App\Models\Modules\Forum\Poll;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFeedSetting;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Services\ConsentService;
use App\Modules\Contributions\Support\ContributionsModule;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
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
    ContributionsModule::setEnabled(false);
    foreach ([FeedItem::class, \App\Models\Post::class, Song::class, \App\Modules\Podcast\Models\PodcastEpisode::class, Event::class, Poll::class, Product::class, PromotionRequest::class, CorpusPair::class, ContributionTask::class] as $class) {
        $class::query()->delete();
    }
});

function edulaAcceptedPair(array $overrides = []): CorpusPair
{
    $user = User::factory()->create();
    app(ConsentService::class)->recordConsent($user);
    $task = ContributionTask::create(['type' => 'translate', 'prompt_text' => 'Hello', 'status' => 'fulfilled', 'is_gold' => false]);
    $submission = ContributionSubmission::create(['contribution_task_id' => $task->id, 'user_id' => $user->id, 'raw_text' => 'Private draft must not leak', 'normalized_text' => 'Ijaarakini', 'status' => 'accepted']);

    return CorpusPair::create(array_merge(['contribution_submission_id' => $submission->id, 'en_text' => 'Hello', 'ateso_text' => 'Ijaarakini', 'license_version' => 'CC-BY-SA-4.0', 'quality_score' => 80, 'provenance' => ['accepted_user_id' => $user->id, 'private_note' => 'secret audit trail']], $overrides));
}

test('old public events including missing end dates are discovered without feed rows', function () {
    $old = Event::factory()->createQuietly(['uuid' => (string) Str::uuid(), 'status' => 'published', 'visibility' => 'public', 'hide_from_non_qualifying' => false, 'starts_at' => now()->subMonth(), 'ends_at' => null, 'published_at' => now()->subMonths(2), 'created_at' => now()->subMonths(2)]);
    Event::factory()->createQuietly(['uuid' => (string) Str::uuid(), 'status' => 'draft', 'visibility' => 'public']);
    Event::factory()->createQuietly(['uuid' => (string) Str::uuid(), 'status' => 'published', 'visibility' => 'private']);
    $response = $this->getJson('/api/feed/timeline?module=events')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.identity'))->toBe('event:'.$old->id);
    expect($response->json('data.0.presentation.state'))->toBe('ended');
    expect($response->json('data.0.published_at'))->toBe($old->published_at->toIso8601String());
    $this->getJson('/api/feed/timeline?view=latest&module=events')->assertOk()->assertJsonCount(1, 'data');
});

test('closed anonymous polls are historical and cannot reveal their creators', function () {
    $user = User::factory()->create();
    $poll = Poll::factory()->createQuietly(['user_id' => $user->id, 'status' => 'closed', 'audience' => 'all', 'is_anonymous' => true, 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth(), 'created_at' => now()->subMonths(2)]);
    Poll::factory()->createQuietly(['status' => 'active', 'audience' => 'all', 'starts_at' => now()->addWeek()]);
    Poll::factory()->createQuietly(['status' => 'closed', 'audience' => 'users']);
    $response = $this->getJson('/api/feed/timeline?module=forum')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.identity'))->toBe('poll:'.$poll->id);
    expect($response->json('data.0.presentation.state'))->toBe('closed');
    expect(json_encode($response->json('data')))->not->toContain($user->name, $user->email);
});

test('active promotion services use public promotion links even when their store is pending', function () {
    $store = Store::factory()->create(['status' => 'pending']);
    $service = Product::factory()->make(['store_id' => $store->id, 'product_type' => 'promotion', 'status' => 'active', 'price_credits' => 200, 'created_at' => now()->subMonths(6), 'published_at' => now()->subMonths(6)]);
    $service->saveQuietly();
    Product::factory()->make(['store_id' => $store->id, 'product_type' => 'promotion', 'status' => 'draft'])->saveQuietly();
    $response = $this->getJson('/api/feed/timeline?module=promotions')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.presentation.kind'))->toBe('service');
    expect($response->json('data.0.presentation.href'))->toBe('/promotions/'.$service->slug);
    expect($response->json('data.0.presentation.price_credits'))->toBe(200);
    $store->updateQuietly(['status' => 'suspended']);
    $this->getJson('/api/feed/timeline?module=promotions')->assertOk()->assertJsonPath('data', []);
});

test('past briefs remain readable but draft and cancelled work never appears', function () {
    $user = User::factory()->create();
    $brief = new PromotionRequest(['created_by_user_id' => $user->id, 'title' => 'Past community brief', 'deadline_at' => now()->subMonth()]);
    $brief->forceFill(['uuid' => (string) Str::uuid(), 'slug' => 'past-brief', 'status' => 'closed', 'created_at' => now()->subMonths(2)])->saveQuietly();
    $response = $this->getJson('/api/feed/timeline?module=promotions')->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.presentation.state'))->toBe('closed');
    $brief->forceFill(['status' => 'cancelled'])->saveQuietly();
    $this->getJson('/api/feed/timeline?module=promotions')->assertOk()->assertJsonPath('data', []);
});

test('accepted licensed corpus pairs are anonymous and respect the module toggle', function () {
    ContributionsModule::setEnabled(true);
    ContributionsModule::setFeedCardsEnabled(true);
    $pair = edulaAcceptedPair();
    edulaAcceptedPair(['license_version' => 'unreleased']);
    edulaAcceptedPair(['en_text' => 'Contact me at private@example.test']);
    $response = $this->getJson('/api/feed/timeline?view=latest&module=ateso')->assertOk();
    $pairs = collect($response->json('data'))->where('presentation.kind', 'corpus');
    expect($pairs)->toHaveCount(1);
    expect($pairs->first()['identity'])->toBe('corpus:'.$pair->id);
    expect($pairs->first()['presentation.ateso_text'] ?? $pairs->first()['presentation']['ateso_text'])->toBe('Ijaarakini');
    expect(json_encode($response->json('data')))->not->toContain('secret audit trail', 'accepted_user_id', 'Private draft must not leak', 'private@example.test');
    ContributionsModule::setFeedCardsEnabled(false);
    $this->getJson('/api/feed/timeline?module=ateso')->assertOk()->assertJsonPath('data', []);
});

test('corpus requires accepted non-gold submissions and recorded consent', function () {
    ContributionsModule::setEnabled(true);
    $pair = edulaAcceptedPair();
    $pair->submission->update(['status' => 'rejected']);
    $this->getJson('/api/feed/timeline?module=ateso')->assertOk()->assertJsonPath('data', []);
    $pair->submission->update(['status' => 'accepted']);
    $pair->submission->task->update(['is_gold' => true, 'gold_answer' => 'SECRET KEY']);
    $response = $this->getJson('/api/feed/timeline?module=ateso')->assertOk();
    expect(json_encode($response->json('data')))->not->toContain('SECRET KEY', 'Ijaarakini');
});

test('native saved items persist and legacy feed bookmarks keep their identity', function () {
    $user = User::factory()->create();
    $event = Event::factory()->createQuietly(['uuid' => (string) Str::uuid(), 'status' => 'published', 'visibility' => 'public', 'hide_from_non_qualifying' => false]);
    $feed = FeedItem::create(['uuid' => (string) Str::uuid(), 'type' => 'event_created', 'module' => 'events', 'visibility' => 'public', 'published_at' => now(), 'subject_type' => Event::class, 'subject_id' => $event->id]);
    UserFeedSetting::create(['user_id' => $user->id, 'preferences' => ['_edula' => ['saved' => ['feed:'.$feed->uuid]]]]);
    $this->actingAs($user)->getJson('/api/feed/timeline/preferences')->assertOk()->assertJsonPath('data.saved', ['event:'.$event->id]);
    $this->getJson('/api/feed/timeline?view=saved')->assertOk()->assertJsonCount(1, 'data');
    $this->putJson('/api/feed/timeline/preferences', ['action' => 'unsave', 'identity' => 'event:'.$event->id])->assertOk()->assertJsonPath('data.saved', []);
    $this->putJson('/api/feed/timeline/preferences', ['action' => 'save', 'identity' => 'event:'.$event->id])->assertOk();
    $this->putJson('/api/feed/timeline/preferences', ['action' => 'hide', 'identity' => 'event:'.$event->id])->assertOk();
    $this->getJson('/api/feed/timeline?view=saved')->assertOk()->assertJsonPath('data', []);
});

test('latest is an uncapped chronological public history with stable cursor pagination', function () {
    $rows = [];
    for ($index = 0; $index < 405; $index++) {
        $rows[] = ['uuid' => (string) Str::uuid(), 'type' => 'announcement', 'module' => 'platform', 'visibility' => 'public', 'published_at' => now()->subYears(2)->subMinutes($index), 'created_at' => now(), 'updated_at' => now()];
    }
    FeedItem::query()->insert($rows);
    $keys = [];
    $cursor = null;
    do {
        $page = $this->getJson('/api/feed/timeline?view=latest&module=platform&per_page=30'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk()->json();
        $keys = array_merge($keys, array_column($page['data'], 'uuid'));
        $cursor = $page['meta']['next_cursor'];
    } while ($cursor);
    expect($keys)->toHaveCount(405);
    expect(array_unique($keys))->toHaveCount(405);
    expect($page['meta']['window_limited'])->toBeFalse();
});

test('waveform peaks are bounded and native duplicates never repeat in a cursor session', function () {
    $artist = Artist::factory()->create(['status' => Artist::STATUS_APPROVED]);
    $song = Song::factory()->create(['artist_id' => $artist->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now(), 'waveform_data' => array_fill(0, 500, .5)]);
    $response = $this->getJson('/api/feed/timeline?view=latest&module=music')->assertOk()->assertJsonCount(1, 'data');
    expect(count($response->json('data.0.presentation.waveform')))->toBeLessThanOrEqual(48);
    expect($response->json('data.0.identity'))->toBe('song:'.$song->id);
});

test('native cursor pages recheck availability without admitting newly published records', function () {
    $events = collect();
    for ($index = 0; $index < 3; $index++) {
        $events->push(Event::factory()->createQuietly(['uuid' => (string) Str::uuid(), 'status' => 'published', 'visibility' => 'public', 'hide_from_non_qualifying' => false, 'published_at' => now()->subDays($index + 1), 'created_at' => now()->subDays($index + 1)]));
    }
    $first = $this->getJson('/api/feed/timeline?view=latest&module=events&per_page=1')->assertOk()->json();
    expect($first['data'][0]['identity'])->toBe('event:'.$events[0]->id);
    $events[1]->updateQuietly(['visibility' => 'private']);
    Event::factory()->createQuietly(['uuid' => (string) Str::uuid(), 'status' => 'published', 'visibility' => 'public', 'hide_from_non_qualifying' => false]);
    $second = $this->getJson('/api/feed/timeline?view=latest&module=events&per_page=1&cursor='.urlencode($first['meta']['next_cursor']))->assertOk()->assertJsonPath('data', [])->json();
    $third = $this->getJson('/api/feed/timeline?view=latest&module=events&per_page=1&cursor='.urlencode($second['meta']['next_cursor']))->assertOk()->assertJsonCount(1, 'data')->json();
    expect($third['data'][0]['identity'])->toBe('event:'.$events[2]->id);
    expect($third['meta']['next_cursor'])->toBeNull();
});
