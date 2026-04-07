<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Playlist;
use App\Models\PlaylistCollaborator;
use App\Models\Song;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PlaylistApiTest extends ResponseStandardizationTestCase
{
    private User $user;

    private Playlist $playlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'visibility' => 'public',
            'is_featured' => true,
        ]);
    }

    // ─── List Playlists ──────────────────────────────────────────

    public function test_list_playlists_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/playlists');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ]);
    }

    public function test_list_playlists_returns_pagination_meta(): void
    {
        Playlist::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'visibility' => 'public',
        ]);

        $response = $this->getJson('/api/playlists');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);
    }

    // ─── Featured Playlists ──────────────────────────────────────

    public function test_featured_playlists_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/playlists/featured');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Single Playlist ─────────────────────────────────────────

    public function test_show_playlist_returns_resource(): void
    {
        $response = $this->getJson("/api/playlists/{$this->playlist->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'links',
                ],
            ]);
    }

    public function test_playlist_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/playlists/nonexistent-playlist-xyz');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Playlist Tracks ─────────────────────────────────────────

    public function test_playlist_tracks_returns_data_wrapper(): void
    {
        $response = $this->getJson("/api/playlists/{$this->playlist->id}/tracks");

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Response Format ─────────────────────────────────────────

    public function test_playlist_responses_contain_no_success_key(): void
    {
        $endpoints = [
            '/api/playlists',
            '/api/playlists/featured',
            "/api/playlists/{$this->playlist->slug}",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $this->assertArrayNotHasKey('success', $response->json(), "Endpoint {$endpoint} still has 'success' key");
        }
    }

    // ─── Authenticated Playlist CRUD ─────────────────────────────

    public function test_create_playlist_returns_resource(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/playlists', [
            'name' => 'My Test Playlist',
            'description' => 'A test playlist',
            'is_public' => true,
        ]);

        $response->assertHeader('Content-Type', 'application/json');
        $this->assertContains($response->status(), [200, 201], 'Create playlist should return 200 or 201');
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'slug'],
        ]);
    }

    public function test_create_playlist_accepts_artwork_upload_and_collaborative_flag(): void
    {
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');

        $response = $this->actingAs($this->user)->post('/api/playlists', [
            'name' => 'Road Trip',
            'is_public' => '1',
            'is_collaborative' => '1',
            'cover_image' => UploadedFile::fake()->image('road-trip.jpg'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_collaborative', true);

        $playlist = Playlist::query()->where('name', 'Road Trip')->firstOrFail();

        $this->assertNotNull($playlist->artwork);
    }

    public function test_create_playlist_accepts_multipart_true_false_boolean_strings(): void
    {
        $response = $this->actingAs($this->user)->post('/api/playlists', [
            'name' => 'Boolean Multipart Playlist',
            'is_public' => 'true',
            'is_collaborative' => 'true',
            'collaboration_requires_approval' => 'false',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.visibility', 'public')
            ->assertJsonPath('data.is_collaborative', true)
            ->assertJsonPath('data.collaboration_requires_approval', false);
    }

    public function test_create_playlist_accepts_collaboration_requires_approval_flag(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/playlists', [
            'name' => 'Invite Only',
            'is_collaborative' => true,
            'collaboration_requires_approval' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.collaboration_requires_approval', true);
    }

    public function test_my_playlists_returns_only_authenticated_users_playlists(): void
    {
        $otherUser = User::factory()->create();
        Playlist::factory()->create([
            'user_id' => $otherUser->id,
            'visibility' => 'public',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/playlists/mine');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->playlist->id);
    }

    public function test_playlist_tracks_endpoint_accepts_song_id_in_request_body(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)->postJson("/api/playlists/{$this->playlist->slug}/tracks", [
            'song_id' => $song->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.song_id', $song->id);
    }

    public function test_playlist_follow_status_and_toggle_use_playlist_routes(): void
    {
        $follower = User::factory()->create();

        $this->actingAs($follower)
            ->getJson("/api/playlists/{$this->playlist->slug}/follow/status")
            ->assertOk()
            ->assertJsonPath('data.is_following', false);

        $this->actingAs($follower)
            ->postJson("/api/playlists/{$this->playlist->slug}/follow")
            ->assertOk()
            ->assertJsonPath('data.is_following', true);

        $this->assertDatabaseHas('user_follows', [
            'follower_id' => $follower->id,
            'followable_id' => $this->playlist->id,
            'followable_type' => Playlist::class,
        ]);

        $this->actingAs($follower)
            ->deleteJson("/api/playlists/{$this->playlist->slug}/follow")
            ->assertOk()
            ->assertJsonPath('data.is_following', false);
    }

    public function test_playlist_owner_can_manage_collaborators(): void
    {
        $collaboratorUser = User::factory()->create();

        $this->playlist->update(['is_collaborative' => true]);

        $this->actingAs($this->user)
            ->postJson("/api/playlists/{$this->playlist->slug}/collaborators", [
                'user_id' => $collaboratorUser->id,
                'role' => 'admin',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $collaboratorUser->id)
            ->assertJsonPath('data.role', 'admin');

        $collaborator = PlaylistCollaborator::query()
            ->where('playlist_id', $this->playlist->id)
            ->where('user_id', $collaboratorUser->id)
            ->firstOrFail();

        $this->actingAs($this->user)
            ->getJson("/api/playlists/{$this->playlist->slug}/collaborators")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->user)
            ->deleteJson("/api/playlists/{$this->playlist->slug}/collaborators/{$collaborator->id}")
            ->assertOk();
    }

    public function test_playlist_owner_can_toggle_collaborative_mode(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/playlists/{$this->playlist->slug}/collaborative", [
                'is_collaborative' => true,
                'collaboration_requires_approval' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_collaborative', true)
            ->assertJsonPath('data.collaboration_requires_approval', true);

        $this->assertDatabaseHas('playlists', [
            'id' => $this->playlist->id,
            'is_collaborative' => 1,
        ]);
    }

    public function test_authenticated_user_can_search_users_for_playlist_collaboration(): void
    {
        $username = 'collabpartner'.fake()->unique()->numberBetween(1000, 9999);
        $targetUser = User::factory()->create([
            'name' => 'Collab Partner',
            'username' => $username,
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/users/search?q=collab')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $targetUser->id,
                'username' => $username,
            ]);
    }

    public function test_playlist_owner_can_generate_and_preview_invite_link(): void
    {
        $this->playlist->update([
            'is_collaborative' => true,
            'collaboration_requires_approval' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/playlists/{$this->playlist->slug}/invite-link", [
                'expires_in_hours' => 24,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_approval', true);

        $token = $response->json('data.invite_token');

        $this->getJson("/api/playlists/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('data.playlist.id', $this->playlist->id)
            ->assertJsonPath('data.requires_approval', true);
    }

    public function test_join_invite_creates_pending_collaborator_when_approval_is_required(): void
    {
        $invitee = User::factory()->create();
        $inviteToken = 'pending-token-'.uniqid();
        $this->playlist->update([
            'is_collaborative' => true,
            'collaboration_requires_approval' => true,
            'collaboration_invite_token' => $inviteToken,
            'collaboration_invite_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($invitee)
            ->postJson("/api/playlists/invites/{$inviteToken}/join")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('playlist_collaborators', [
            'playlist_id' => $this->playlist->id,
            'user_id' => $invitee->id,
            'status' => 'pending',
        ]);
    }

    public function test_playlist_owner_can_approve_pending_collaborator(): void
    {
        $invitee = User::factory()->create();
        $this->playlist->update([
            'is_collaborative' => true,
            'collaboration_requires_approval' => true,
        ]);

        $collaborator = PlaylistCollaborator::create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $invitee->id,
            'role' => 'editor',
            'status' => 'pending',
            'joined_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/playlists/{$this->playlist->slug}/collaborators/{$collaborator->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('playlist_collaborators', [
            'id' => $collaborator->id,
            'status' => 'accepted',
        ]);
    }

    public function test_playlist_owner_can_update_collaborator_role(): void
    {
        $collaboratorUser = User::factory()->create();
        $this->playlist->update(['is_collaborative' => true]);

        $collaborator = PlaylistCollaborator::create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $collaboratorUser->id,
            'role' => 'editor',
            'status' => 'accepted',
            'approved_at' => now(),
            'joined_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/playlists/{$this->playlist->slug}/collaborators/{$collaborator->id}/role", [
                'role' => 'viewer',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.can_edit', false);
    }

    public function test_playlist_owner_can_reorder_songs(): void
    {
        $songOne = Song::factory()->create();
        $songTwo = Song::factory()->create();

        $this->playlist->songs()->attach($songOne->id, ['position' => 1, 'added_by' => $this->user->id]);
        $this->playlist->songs()->attach($songTwo->id, ['position' => 2, 'added_by' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson("/api/playlists/{$this->playlist->slug}/reorder", [
                'song_ids' => [$songTwo->id, $songOne->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.song_ids.0', $songTwo->id);

        $this->assertDatabaseHas('playlist_songs', [
            'playlist_id' => $this->playlist->id,
            'song_id' => $songTwo->id,
            'position' => 1,
        ]);
    }

    public function test_playlist_owner_can_remove_artwork(): void
    {
        $this->playlist->update(['artwork' => 'playlists/artwork/test.jpg']);

        $this->actingAs($this->user)
            ->deleteJson("/api/playlists/{$this->playlist->slug}/artwork")
            ->assertOk();

        $this->assertDatabaseHas('playlists', [
            'id' => $this->playlist->id,
            'artwork' => null,
        ]);
    }

    public function test_playlist_suggested_songs_endpoint_returns_song_data(): void
    {
        Song::factory()->count(2)->create();

        $this->getJson("/api/playlists/{$this->playlist->slug}/suggested-songs")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
