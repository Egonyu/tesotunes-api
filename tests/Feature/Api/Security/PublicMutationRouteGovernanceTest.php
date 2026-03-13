<?php

namespace Tests\Feature\Api\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicMutationRouteGovernanceTest extends TestCase
{
    public function test_intentional_public_mutation_routes_are_throttled(): void
    {
        $themeRoute = Route::getRoutes()->match(Request::create('/api/theme', 'POST'));
        $impressionRoute = Route::getRoutes()->match(Request::create('/api/ads/impression', 'POST'));
        $clickRoute = Route::getRoutes()->match(Request::create('/api/ads/click', 'POST'));
        $distributionWebhookRoute = Route::getRoutes()->match(Request::create('/api/webhooks/distribution/spotify', 'POST'));

        $this->assertContains('throttle:theme', $themeRoute->gatherMiddleware());
        $this->assertContains('throttle:ad-tracking', $impressionRoute->gatherMiddleware());
        $this->assertContains('throttle:ad-tracking', $clickRoute->gatherMiddleware());
        $this->assertContains('webhook.rate_limit', $distributionWebhookRoute->gatherMiddleware());
    }

    public function test_legacy_v1_mutation_routes_still_require_authentication(): void
    {
        $this->postJson('/api/v1/player/record-play', ['song_id' => 1])->assertUnauthorized();
        $this->putJson('/api/v1/user', ['name' => 'Guest'])->assertUnauthorized();
        $this->postJson('/api/v1/playlists', ['name' => 'Guest Playlist'])->assertUnauthorized();
        $this->getJson('/api/v1/tracks/1/download-url')->assertUnauthorized();
    }
}
