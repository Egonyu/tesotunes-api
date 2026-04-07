<?php

declare(strict_types=1);

use App\Helpers\StorageHelper;
use App\Http\Resources\SongResource;
use App\Http\Resources\SongStreamingAccessResource;
use App\Models\Song;
use Illuminate\Http\Request;

it('includes stream and audio urls for streamable songs', function (): void {
    $song = new Song;
    $song->is_free = true;
    $song->audio_file_128 = 'songs/128/test-stream.mp3';

    $request = Request::create('/api/songs/test', 'GET');

    $payload = SongStreamingAccessResource::make($song)->resolve($request);

    $expectedUrl = StorageHelper::streamingUrl(null, 'songs/128/test-stream.mp3', null);

    expect($payload['stream_url'])->toBe($expectedUrl);
    expect($payload['audio_url'])->toBe($expectedUrl);
});

it('omits stream and audio urls for users without stream access', function (): void {
    $song = new Song;
    $song->is_free = false;
    $song->audio_file_128 = 'songs/128/test-stream.mp3';

    $request = Request::create('/api/songs/test', 'GET');
    $request->setUserResolver(fn () => new class
    {
        public function canStream(): bool
        {
            return false;
        }

        public function getMaxAudioQuality(): int
        {
            return 128;
        }
    });

    $payload = SongStreamingAccessResource::make($song)->resolve($request);

    expect($payload['stream_url'])->toBeNull();
    expect($payload['audio_url'])->toBeNull();
});

it('includes preview url when preview file exists', function (): void {
    $song = new Song;
    $song->is_free = true;
    $song->audio_file_preview = 'songs/preview/test-preview.mp3';

    $request = Request::create('/api/songs/test', 'GET');

    $payload = SongStreamingAccessResource::make($song)->resolve($request);

    expect($payload['preview_url'])->toBe(StorageHelper::temporaryUrl('songs/preview/test-preview.mp3', 30));
});

it('uses 320kbps stream when user entitlement allows it', function (): void {
    $song = new Song;
    $song->is_free = false;
    $song->audio_file_320 = 'songs/320/test-stream.mp3';
    $song->audio_file_128 = 'songs/128/test-stream.mp3';

    $request = Request::create('/api/songs/test', 'GET');
    $request->setUserResolver(fn () => new class
    {
        public function canStream(): bool
        {
            return true;
        }

        public function getMaxAudioQuality(): int
        {
            return 320;
        }
    });

    $payload = SongStreamingAccessResource::make($song)->resolve($request);

    expect($payload['stream_url'])->toBe(StorageHelper::streamingUrl('songs/320/test-stream.mp3', 'songs/128/test-stream.mp3', null));
    expect($payload['audio_url'])->toBe(StorageHelper::streamingUrl('songs/320/test-stream.mp3', 'songs/128/test-stream.mp3', null));
});

it('omits preview url when preview file does not exist', function (): void {
    $song = new Song;
    $song->is_free = true;

    $request = Request::create('/api/songs/test', 'GET');

    $payload = SongStreamingAccessResource::make($song)->resolve($request);

    expect($payload['preview_url'])->toBeNull();
});

it('merges extracted streaming fields into song resource output', function (): void {
    $song = new Song;
    $song->id = 99;
    $song->title = 'Test Song';
    $song->is_free = true;
    $song->audio_file_128 = 'songs/128/test-stream.mp3';
    $song->audio_file_preview = 'songs/preview/test-preview.mp3';

    $request = Request::create('/api/songs/test', 'GET');

    $payload = SongResource::make($song)->resolve($request);

    expect($payload['stream_url'])->toBe(StorageHelper::streamingUrl(null, 'songs/128/test-stream.mp3', null));
    expect($payload['audio_url'])->toBe(StorageHelper::streamingUrl(null, 'songs/128/test-stream.mp3', null));
    expect($payload['preview_url'])->toBe(StorageHelper::temporaryUrl('songs/preview/test-preview.mp3', 30));
});

it('omits merged streaming fields in song resource when user cannot stream', function (): void {
    $song = new Song;
    $song->id = 100;
    $song->title = 'Locked Song';
    $song->is_free = false;
    $song->audio_file_128 = 'songs/128/test-stream.mp3';

    $request = Request::create('/api/songs/test', 'GET');
    $request->setUserResolver(fn () => new class
    {
        public function canStream(): bool
        {
            return false;
        }

        public function getMaxAudioQuality(): int
        {
            return 128;
        }
    });

    $payload = SongResource::make($song)->resolve($request);

    expect($payload['stream_url'])->toBeNull();
    expect($payload['audio_url'])->toBeNull();
});

it('includes canonical duration and media keys in song resource output', function (): void {
    $song = new Song;
    $song->id = 101;
    $song->title = 'Canonical Contract';
    $song->duration_seconds = 185;
    $song->is_free = true;
    $song->audio_file_128 = 'songs/128/test-stream.mp3';

    $request = Request::create('/api/songs/test', 'GET');

    $payload = SongResource::make($song)->resolve($request);

    expect($payload['duration_seconds'])->toBe(185);
    expect($payload['duration_formatted'])->toBe('3:05');
    expect($payload)->toHaveKeys(['audio_url', 'stream_url', 'preview_url', 'artwork_url']);
});
