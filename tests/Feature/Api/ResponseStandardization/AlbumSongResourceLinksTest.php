<?php

use App\Http\Resources\AlbumResource;
use App\Http\Resources\SongResource;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;

uses(DatabaseTransactions::class);

test('album resource links use named routes with album slug and artist slug', function () {
    $artist = Artist::factory()->create([
        'slug' => 'teso-artist',
        'stage_name' => 'Teso Artist',
    ]);

    $album = Album::factory()->create([
        'artist_id' => $artist->id,
        'slug' => 'teso-album',
        'status' => 'published',
    ]);

    $payload = (new AlbumResource($album->load('artist')))->toArray(Request::create('/api/albums', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['self'])->toBe(route('api.music.album', ['album' => 'teso-album']));
    expect($payload['links']['tracks'])->toBe(route('api.music.album.tracks', ['album' => 'teso-album']));
    expect($payload['links']['artist'])->toBe(route('api.music.artist', ['artist' => 'teso-artist']));
});

test('song resource album link uses album slug when album relation is loaded', function () {
    $artist = Artist::factory()->create(['slug' => 'song-owner']);
    $album = Album::factory()->create([
        'artist_id' => $artist->id,
        'slug' => 'linked-album',
        'status' => 'published',
    ]);

    $song = Song::factory()->create([
        'artist_id' => $artist->id,
        'album_id' => $album->id,
        'slug' => 'linked-song',
        'status' => 'published',
    ]);

    $payload = (new SongResource($song->load('album', 'artist')))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['album'])->toContain('/api/albums/linked-album');
});

test('song resource album link falls back to album id when relation is not loaded', function () {
    $artist = Artist::factory()->create(['slug' => 'song-owner-2']);
    $album = Album::factory()->create([
        'artist_id' => $artist->id,
        'slug' => 'fallback-album',
        'status' => 'published',
    ]);

    $song = Song::factory()->create([
        'artist_id' => $artist->id,
        'album_id' => $album->id,
        'slug' => 'fallback-song',
        'status' => 'published',
    ]);

    $payload = (new SongResource($song))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['album'])->toContain('/api/albums/'.$album->id);
});

test('song resource self link falls back to id when slug is missing', function () {
    $artist = Artist::factory()->create(['slug' => 'fallback-owner']);
    $song = Song::factory()->make([
        'id' => 98765,
        'artist_id' => $artist->id,
        'slug' => null,
    ]);

    $payload = (new SongResource($song))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['self'])->toContain('/api/songs/98765');
});

test('song resource album link falls back to loaded album id when loaded album slug is missing', function () {
    $artist = Artist::factory()->create(['slug' => 'fallback-owner-2']);
    $album = Album::factory()->create([
        'artist_id' => $artist->id,
        'slug' => 'regular-slug',
        'status' => 'published',
    ]);

    $song = Song::factory()->create([
        'artist_id' => $artist->id,
        'album_id' => $album->id,
        'slug' => 'song-loaded-fallback',
        'status' => 'published',
    ]);

    $song->setRelation('album', Album::factory()->make([
        'id' => $album->id,
        'artist_id' => $artist->id,
        'slug' => null,
    ]));

    $payload = (new SongResource($song->load('artist')))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['album'])->toContain('/api/albums/'.$album->id);
});

test('album resource artist link falls back to artist id when artist slug is missing', function () {
    $artist = Artist::factory()->create(['slug' => 'temp-artist']);
    $album = Album::factory()->create([
        'artist_id' => $artist->id,
        'slug' => 'album-with-fallback-artist',
        'status' => 'published',
    ]);

    $album->setRelation('artist', Artist::factory()->make([
        'id' => $artist->id,
        'slug' => null,
        'stage_name' => $artist->stage_name,
    ]));

    $payload = (new AlbumResource($album))->toArray(Request::create('/api/albums', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['artist'])->toContain('/api/artists/'.$artist->id);
});

test('song resource artist link uses artist slug when available', function () {
    $artist = Artist::factory()->create(['slug' => 'song-artist-slug']);
    $song = Song::factory()->create([
        'artist_id' => $artist->id,
        'slug' => 'song-with-artist-slug',
        'status' => 'published',
    ]);

    $payload = (new SongResource($song->load('artist')))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['artist'])->toBe(route('api.music.artist', ['artist' => 'song-artist-slug']));
});

test('song resource artist link falls back to loaded artist id when loaded artist slug is missing', function () {
    $song = Song::factory()->make([
        'artist_id' => null,
        'artist_slug' => null,
        'slug' => 'song-artist-id-fallback',
    ]);

    $song->setRelation('artist', Artist::factory()->make([
        'id' => 654321,
        'slug' => null,
        'stage_name' => 'No Slug Artist',
    ]));

    $payload = (new SongResource($song))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['artist'])->toBe(route('api.music.artist', ['artist' => 654321]));
});

test('song resource artist link is null when no artist route key can be resolved', function () {
    $song = Song::factory()->make([
        'artist_id' => null,
        'artist_slug' => null,
        'slug' => 'song-no-artist-link',
    ]);

    $payload = (new SongResource($song))->toArray(Request::create('/api/songs', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['artist'])->toBeNull();
});
