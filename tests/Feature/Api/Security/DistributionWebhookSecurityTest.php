<?php

namespace Tests\Feature\Api\Security;

use App\Models\Artist;
use App\Models\Distribution;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DistributionWebhookSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $platform = 'spotify';

    private string $secret = 'distribution-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.distribution.webhook_secret', $this->secret);
        config()->set("services.distribution.webhook_secrets.{$this->platform}", $this->secret);
        Cache::flush();
    }

    private function sendSignedWebhook(array $payload, array $headers = [])
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            "/api/webhooks/distribution/{$this->platform}",
            [],
            [],
            [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $this->secret),
            ], $headers),
            $body
        );
    }

    private function createDistribution(): Distribution
    {
        $user = User::factory()->create([
            'role' => 'artist',
            'is_active' => true,
        ]);
        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $song = Song::factory()->create([
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);

        return Distribution::create([
            'song_id' => $song->id,
            'artist_id' => $artist->id,
            'platform_code' => $this->platform,
            'platform_name' => 'Spotify',
            'status' => 'pending',
            'platform_id' => 'platform-123',
        ]);
    }

    public function test_distribution_webhook_rejects_invalid_signature(): void
    {
        $response = $this->withHeaders([
            'X-Signature' => 'bad-signature',
        ])->postJson("/api/webhooks/distribution/{$this->platform}", [
            'event' => 'live',
            'platform_id' => 'platform-123',
        ]);

        $response->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid signature.',
            ]);
    }

    public function test_distribution_webhook_updates_distribution_with_valid_signature(): void
    {
        $distribution = $this->createDistribution();
        $payload = [
            'event' => 'live',
            'platform_id' => $distribution->platform_id,
            'url' => 'https://open.spotify.com/track/example',
            'event_id' => 'evt-live-1',
        ];

        $response = $this->sendSignedWebhook($payload, [
            'HTTP_X_EVENT_ID' => 'evt-live-1',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Distribution marked as live',
            ]);

        $this->assertDatabaseHas('distributions', [
            'id' => $distribution->id,
            'status' => 'live',
        ]);
    }

    public function test_distribution_webhook_replay_is_ignored_after_first_processing(): void
    {
        $distribution = $this->createDistribution();
        $payload = [
            'event' => 'live',
            'platform_id' => $distribution->platform_id,
            'event_id' => 'evt-live-replay',
        ];

        $this->sendSignedWebhook($payload, [
            'HTTP_X_EVENT_ID' => 'evt-live-replay',
        ])
            ->assertOk();

        $response = $this->sendSignedWebhook($payload, [
            'HTTP_X_EVENT_ID' => 'evt-live-replay',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Webhook already processed.',
            ]);
    }
}
