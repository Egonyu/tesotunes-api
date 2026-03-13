<?php

namespace Tests\Feature\Api\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyV1DeprecationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_v1_routes_return_deprecation_headers(): void
    {
        $response = $this->getJson('/api/v1/public/trending');

        $response->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Link', '</api>; rel="successor-version"');

        $this->assertStringContainsString('30 Jun 2026', $response->headers->get('Sunset'));
    }

    public function test_authenticated_v1_routes_return_deprecation_headers(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user');

        $response->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Link', '</api>; rel="successor-version"');
    }
}
