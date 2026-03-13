<?php

namespace Tests\Feature\Api\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyV1AuthConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_v1_protected_routes_use_sanctum_instead_of_web_auth(): void
    {
        $userRoute = Route::getRoutes()->match(Request::create('/api/v1/user', 'GET'));
        $downloadRoute = Route::getRoutes()->match(Request::create('/api/v1/tracks/1/download-url', 'GET'));
        $recordPlayRoute = Route::getRoutes()->match(Request::create('/api/v1/player/record-play', 'POST'));

        $this->assertContains('auth:sanctum', $userRoute->gatherMiddleware());
        $this->assertContains('auth:sanctum', $downloadRoute->gatherMiddleware());
        $this->assertContains('auth:sanctum', $recordPlayRoute->gatherMiddleware());

        $this->assertNotContains('web', $userRoute->gatherMiddleware());
        $this->assertNotContains('auth', $userRoute->gatherMiddleware());
        $this->assertNotContains('auth:web', $downloadRoute->gatherMiddleware());
        $this->assertNotContains('auth:web', $recordPlayRoute->gatherMiddleware());
    }

    public function test_v1_user_and_token_routes_accept_sanctum_authenticated_requests(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->getJson('/api/v1/auth/tokens')
            ->assertOk()
            ->assertJsonStructure(['tokens']);
    }
}
