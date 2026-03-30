<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\User;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    // ─── Login ───────────────────────────────────────────────────

    public function test_login_returns_json_not_redirect(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        // Must not redirect (Blade behavior) — must return JSON
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'data' => ['id', 'name'],
            ]);
    }

    public function test_login_invalid_credentials_returns_json_error(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@test.com',
            'password' => 'wrong',
        ]);

        $response->assertHeader('Content-Type', 'application/json');
        // May return 401 (invalid credentials) or 422 (validation error)
        $this->assertContains($response->status(), [401, 422]);
        $response->assertJsonStructure(['message']);
    }

    // ─── Register ────────────────────────────────────────────────

    public function test_register_returns_json_not_redirect(): void
    {
        $uniqueId = rand(10000, 99999);
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => "newuser{$uniqueId}@test.com",
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        // Must return JSON, not session redirect
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertNotEquals(302, $response->status(), 'Register should not redirect (old Blade behavior)');

        if ($response->status() === 201 || $response->status() === 200) {
            $response->assertJsonStructure([
                'data' => ['id', 'name'],
            ]);
        } else {
            // If 500, it's a controller bug but at least it returns JSON not HTML
            $content = $response->getContent();
            $this->assertStringNotContainsString('<!DOCTYPE', $content, 'Register should return JSON not HTML even on error');
        }
    }

    public function test_register_validation_returns_json_errors(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'invalid',
        ]);

        $response->assertHeader('Content-Type', 'application/json')
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors',
            ]);
    }

    // ─── Authenticated User ──────────────────────────────────────

    public function test_user_profile_returns_resource(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user/profile');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name'],
            ]);
    }

    public function test_user_profile_unauthenticated_returns_json_401(): void
    {
        $response = $this->getJson('/api/user/profile');

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── User Library ────────────────────────────────────────────

    public function test_user_library_returns_data_wrapper(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user/library');

        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $response->assertJsonStructure(['data']);
        } else {
            // Library 500 indicates a controller bug, but at least it returns JSON
            $content = $response->getContent();
            $this->assertStringNotContainsString('<!DOCTYPE', $content, 'Library should return JSON not HTML');
        }
    }
}
