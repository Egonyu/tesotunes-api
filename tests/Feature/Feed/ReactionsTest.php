<?php

use App\Models\EdulaReaction;
use App\Models\FeedItem;
use App\Models\Post;
use App\Models\User;
use App\Settings\SettingsManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    Queue::fake();
    Notification::fake();
    Cache::flush();
    FeedItem::query()->delete();
    Post::query()->delete();
    EdulaReaction::query()->delete();
});

function reactionItem(array $attributes = []): FeedItem
{
    return FeedItem::create(array_merge([
        'uuid' => (string) Str::uuid(), 'type' => 'announcement', 'module' => 'platform',
        'title' => 'A public update', 'visibility' => 'public', 'published_at' => now(),
        'tags' => [], 'extras' => [], 'actions' => [], 'base_rank_boost' => 10,
    ], $attributes));
}

test('the reaction set comes from the operator setting and ignores malformed entries', function () {
    app(SettingsManager::class)->set('general_edula_reactions', 'ejok:Ejok!, eyalama:Eyalama ,Bad Key:Nope,empty:,ejok_noi:Ejok noi');
    expect(app(\App\Services\EdulaReactionService::class)->options())->toBe(['ejok' => 'Ejok!', 'eyalama' => 'Eyalama', 'ejok_noi' => 'Ejok noi']);
});

test('members react once per item, can change or clear it, and the timeline shows counts and their own choice', function () {
    $item = reactionItem();
    $identity = 'feed:'.$item->uuid;
    [$first, $second, $third] = User::factory()->count(3)->create();

    $this->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => 'ejok'])->assertUnauthorized();
    $this->actingAs($first)->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => 'ejok'])->assertOk()
        ->assertJsonPath('data.mine', 'ejok')->assertJsonPath('data.counts.ejok', 1);
    $this->actingAs($second)->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => 'ejok'])->assertOk()->assertJsonPath('data.counts.ejok', 2);
    $this->actingAs($third)->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => 'eyalama'])->assertOk();

    $changed = $this->actingAs($second)->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => 'eyalama'])->assertOk();
    expect($changed->json('data.counts'))->toBe(['ejok' => 1, 'eyalama' => 2]);
    expect(EdulaReaction::query()->where('identity', $identity)->count())->toBe(3);

    $timeline = $this->actingAs($first)->getJson('/api/feed/timeline')->assertOk();
    expect($timeline->json('data.0.reactions.counts'))->toBe(['ejok' => 1, 'eyalama' => 2]);
    expect($timeline->json('data.0.reactions.mine'))->toBe('ejok');
    $this->app->make('auth')->forgetGuards();
    $public = $this->getJson('/api/feed/timeline')->assertOk();
    expect($public->json('data.0.reactions.mine'))->toBeNull();
    expect($public->json('data.0.reactions.counts.eyalama'))->toBe(2);

    $this->actingAs($first)->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => null])->assertOk()
        ->assertJsonPath('data.mine', null)->assertJsonPath('data.counts', ['eyalama' => 2]);
});

test('reactions reject unknown keys, malformed identities and items the member cannot see', function () {
    $member = User::factory()->create();
    $author = User::factory()->create();
    $item = reactionItem();
    $private = Post::create(['user_id' => $author->id, 'content' => 'Just for me', 'visibility' => 'private', 'published_at' => now()]);

    $this->actingAs($member)->putJson('/api/feed/reactions', ['identity' => 'feed:'.$item->uuid, 'reaction' => 'thumbs'])->assertUnprocessable();
    $this->actingAs($member)->putJson('/api/feed/reactions', ['identity' => 'wallet:1', 'reaction' => 'ejok'])->assertUnprocessable();
    $this->actingAs($member)->putJson('/api/feed/reactions', ['identity' => 'post:'.$private->id, 'reaction' => 'ejok'])->assertNotFound();
    $this->actingAs($member)->putJson('/api/feed/reactions', ['identity' => 'post:999999999', 'reaction' => 'ejok'])->assertNotFound();
    expect(EdulaReaction::query()->count())->toBe(0);
});

test('a retired reaction stops being counted without deleting what members chose', function () {
    $item = reactionItem();
    $identity = 'feed:'.$item->uuid;
    $member = User::factory()->create();
    $this->actingAs($member)->putJson('/api/feed/reactions', ['identity' => $identity, 'reaction' => 'eyalama'])->assertOk();

    app(SettingsManager::class)->set('general_edula_reactions', 'ejok:Ejok!');
    $timeline = $this->actingAs($member)->getJson('/api/feed/timeline')->assertOk();
    expect($timeline->json('data.0.reactions.counts'))->toBe([]);
    expect($timeline->json('data.0.reactions.mine'))->toBeNull();
    expect(EdulaReaction::query()->count())->toBe(1);
});
