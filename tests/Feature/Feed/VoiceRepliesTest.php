<?php

use App\Helpers\StorageHelper;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Settings\SettingsManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    Queue::fake();
    Notification::fake();
    Storage::fake(StorageHelper::resolvedMediaDisk());
    app(SettingsManager::class)->set('general_edula_voice_reply_seconds', 60);
});

function voicePost(): Post
{
    $author = User::factory()->create();

    return Post::create(['user_id' => $author->id, 'content' => 'Who was at Akogo Night?', 'visibility' => 'public', 'published_at' => now()]);
}

test('a member replies with a voice note and the discussion returns an absolute audio URL', function () {
    $post = voicePost();
    $member = User::factory()->create();

    $response = $this->actingAs($member)->post('/api/posts/'.$post->id.'/comments', [
        'audio' => UploadedFile::fake()->create('reply.webm', 120, 'audio/webm'),
        'audio_duration_seconds' => 14,
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($response->json('data.content'))->toBe('');
    expect($response->json('data.audio_duration_seconds'))->toBe(14);
    expect($response->json('data.audio_url'))->toStartWith('http');
    $comment = PostComment::query()->findOrFail($response->json('data.id'));
    Storage::disk(StorageHelper::resolvedMediaDisk())->assertExists($comment->audio_path);
    expect($post->fresh()->comments_count)->toBe(1);

    $listing = $this->getJson('/api/posts/'.$post->id.'/comments')->assertOk();
    expect($listing->json('data.0.audio_url'))->toBe($response->json('data.audio_url'));
});

test('text replies keep working, and a reply needs text or audio', function () {
    $post = voicePost();
    $member = User::factory()->create();

    $this->actingAs($member)->postJson('/api/posts/'.$post->id.'/comments', ['content' => 'I was there!'])->assertCreated()
        ->assertJsonPath('data.audio_url', null);
    $this->actingAs($member)->postJson('/api/posts/'.$post->id.'/comments', [])->assertUnprocessable()->assertJsonValidationErrors('content');
});

test('voice replies respect the operator limit, the size cap and audio types, and can be turned off', function () {
    $post = voicePost();
    $member = User::factory()->create();
    $send = fn (array $fields) => $this->actingAs($member)->post('/api/posts/'.$post->id.'/comments', $fields, ['Accept' => 'application/json']);

    $send(['audio' => UploadedFile::fake()->create('long.webm', 100, 'audio/webm'), 'audio_duration_seconds' => 61])->assertUnprocessable()->assertJsonValidationErrors('audio_duration_seconds');
    $send(['audio' => UploadedFile::fake()->create('big.webm', 3000, 'audio/webm'), 'audio_duration_seconds' => 10])->assertUnprocessable()->assertJsonValidationErrors('audio');
    $send(['audio' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 'audio_duration_seconds' => 10])->assertUnprocessable()->assertJsonValidationErrors('audio');
    $send(['audio' => UploadedFile::fake()->create('reply.webm', 50, 'audio/webm')])->assertUnprocessable()->assertJsonValidationErrors('audio_duration_seconds');

    app(SettingsManager::class)->set('general_edula_voice_reply_seconds', 0);
    $send(['audio' => UploadedFile::fake()->create('reply.webm', 50, 'audio/webm'), 'audio_duration_seconds' => 1])->assertUnprocessable();
    expect(PostComment::query()->where('post_id', $post->id)->count())->toBe(0);
});

test('deleting a voice reply removes the recording', function () {
    $post = voicePost();
    $member = User::factory()->create();
    $id = $this->actingAs($member)->post('/api/posts/'.$post->id.'/comments', [
        'audio' => UploadedFile::fake()->create('reply.webm', 40, 'audio/webm'),
        'audio_duration_seconds' => 5,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $path = PostComment::query()->findOrFail($id)->audio_path;

    $this->actingAs($member)->deleteJson('/api/posts/'.$post->id.'/comments/'.$id)->assertOk();
    Storage::disk(StorageHelper::resolvedMediaDisk())->assertMissing($path);
});
