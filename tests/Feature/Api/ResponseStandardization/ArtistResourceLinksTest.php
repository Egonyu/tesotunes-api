<?php

use App\Http\Resources\ArtistResource;
use App\Models\Artist;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;

uses(DatabaseTransactions::class);

test('artist resource links use named routes with slug key', function () {
    $artist = Artist::factory()->create([
        'slug' => 'teso-star',
        'stage_name' => 'Teso Star',
    ]);

    $payload = (new ArtistResource($artist))->toArray(Request::create('/api/artists', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['self'])->toContain('/api/artists/teso-star');
    expect($payload['links']['songs'])->toContain('/api/artists/teso-star/songs');
    expect($payload['links']['albums'])->toContain('/api/artists/teso-star/albums');
});

test('artist resource links are null when slug is missing', function () {
    $artist = Artist::factory()->make([
        'slug' => null,
        'stage_name' => 'Legacy Artist',
    ]);

    $payload = (new ArtistResource($artist))->toArray(Request::create('/api/artists', 'GET'));

    expect($payload)->toHaveKey('links');
    expect($payload['links']['self'])->toBeNull();
    expect($payload['links']['songs'])->toBeNull();
    expect($payload['links']['albums'])->toBeNull();
});
