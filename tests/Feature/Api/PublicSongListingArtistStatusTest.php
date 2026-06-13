<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression: public song listings filter by `artists.status`. After the
 * 2026-05-19 KYC canonicalize migration, prod data was rewritten so every
 * artist row uses 'approved' (legacy 'active'/'verified' merged in). The
 * listing controllers historically filtered on the bare string 'active',
 * which silently returned zero rows once the rename landed.
 *
 * These tests pin the new contract:
 *
 *   - GET /api/songs surfaces a published song whose artist is 'approved'.
 *   - GET /api/songs surfaces a published song whose artist is the legacy
 *     'active' value too, during the backward-compat window
 *     (Artist::VISIBLE_STATUSES).
 *   - GET /api/artists surfaces an 'approved' artist.
 *   - GET /api/artists/{slug}/songs returns the artist's published songs.
 */
class PublicSongListingArtistStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_songs_list_includes_song_from_approved_artist(): void
    {
        $artist = Artist::factory()->create([
            'status' => Artist::STATUS_APPROVED,
        ]);

        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/songs');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($song->id, $ids->all(), 'Published song from an approved artist must appear in /api/songs');
    }

    public function test_public_songs_list_still_includes_song_from_legacy_active_artist(): void
    {
        // Defensive: if any artist row somehow still has the legacy value
        // (e.g. a code path created an artist with 'active' before the
        // canonicalization commits landed), it should still appear in
        // listings until we drop the compat shim.
        $artist = Artist::factory()->create();
        // Bypass enum cast to write the legacy string directly.
        Artist::query()->where('id', $artist->id)->update(['status' => 'active']);

        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/songs');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($song->id, $ids->all(), 'Legacy active-status artist must still surface during compat window');
    }

    public function test_artist_songs_endpoint_returns_published_songs_for_approved_artist(): void
    {
        // Regression: GET /api/artists/{slug}/songs filtered artists on the bare
        // string 'active', so after canonicalization an approved artist's
        // profile showed zero songs even though they appeared in /api/songs.
        $artist = Artist::factory()->create([
            'status' => Artist::STATUS_APPROVED,
        ]);

        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);

        $response = $this->getJson("/api/artists/{$artist->slug}/songs");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($song->id, $ids->all(), "An approved artist's published song must appear on /api/artists/{slug}/songs");
    }

    public function test_artist_albums_endpoint_resolves_for_approved_artist(): void
    {
        $artist = Artist::factory()->create([
            'status' => Artist::STATUS_APPROVED,
        ]);

        // The endpoint must resolve the approved artist (200), not 404 on the
        // old 'active'-only gate.
        $response = $this->getJson("/api/artists/{$artist->slug}/albums");

        $response->assertOk();
    }

    public function test_public_artists_list_includes_approved_artist(): void
    {
        $artist = Artist::factory()->create([
            'status' => Artist::STATUS_APPROVED,
        ]);

        $response = $this->getJson('/api/artists');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertContains($artist->slug, $slugs->all(), 'Approved artist must appear in /api/artists');
    }

    public function test_visible_statuses_constant_matches_canonical_enum_plus_legacy(): void
    {
        // The compat shim should accept the canonical 'approved' and exactly
        // one legacy value ('active'). 'verified' was already remapped to
        // 'approved' in the migration and should NOT remain in the shim.
        $this->assertSame(['approved', 'active'], Artist::VISIBLE_STATUSES);
        $this->assertSame('approved', \App\Enums\ArtistStatus::Approved->value);
    }
}
