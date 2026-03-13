<?php

namespace Tests\Feature\Api\Security;

use Tests\TestCase;

class AuthSurfaceTest extends TestCase
{
    public function test_legacy_web_auth_login_surface_is_not_exposed(): void
    {
        $this->postJson('/auth/login', [
            'email' => 'legacy@example.com',
            'password' => 'password123',
        ])->assertNotFound();
    }

    public function test_legacy_web_auth_register_surface_is_not_exposed(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();
    }
}
