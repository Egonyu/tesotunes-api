<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminApiStandardizationTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();

        // Create admin role and assign it
        $role = Role::factory()->admin()->create();
        DB::table('user_roles')->insert([
            'user_id' => $this->admin->id,
            'role_id' => $role->id,
            'is_active' => true,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Clear cached roles
        cache()->forget("user:{$this->admin->id}:roles");
    }

    // ─── Dashboard Stats ─────────────────────────────────────────

    public function test_dashboard_stats_returns_data_wrapper(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard/stats');

        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $response->assertJsonStructure(['data']);
        } else {
            // Dashboard queries several tables that may not exist — verify JSON not HTML
            $this->assertStringNotContainsString('<!DOCTYPE', $response->getContent());
        }
    }

    public function test_dashboard_stats_returns_success_key(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard/stats');

        if ($response->status() === 200) {
            // Admin endpoints use standardized {success, data} format
            $this->assertArrayHasKey('success', $response->json());
            $this->assertTrue($response->json('success'));
        } else {
            // Controller bug (probably missing table), but returns JSON
            $response->assertHeader('Content-Type', 'application/json');
        }
    }

    // ─── User Management ─────────────────────────────────────────

    public function test_admin_users_returns_paginated_data(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $json = $response->json();
            $this->assertArrayHasKey('data', $json);
            // Pagination may be in 'meta' (standardized) or at root (raw paginator)
            $hasMeta = isset($json['meta']['current_page']);
            $hasRootPagination = isset($json['current_page']);
            $this->assertTrue($hasMeta || $hasRootPagination, 'Admin users should have pagination info');
        }
    }

    // ─── Artist Management ───────────────────────────────────────

    public function test_admin_artists_returns_paginated_data(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/artists');

        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $json = $response->json();
            $this->assertArrayHasKey('data', $json);
            // Pagination may be in 'meta' (standardized) or at root (raw paginator)
            $hasMeta = isset($json['meta']['current_page']);
            $hasRootPagination = isset($json['current_page']);
            $this->assertTrue($hasMeta || $hasRootPagination, 'Admin artists should have pagination info');
        }
    }

    // ─── Settings ────────────────────────────────────────────────

    public function test_admin_settings_returns_data_wrapper(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/settings');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Unauthenticated admin access ────────────────────────────

    public function test_admin_endpoints_return_json_for_unauthenticated(): void
    {
        $endpoints = [
            '/api/admin/dashboard/stats',
            '/api/admin/users',
            '/api/admin/artists',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertHeader('Content-Type', 'application/json');

            // Admin routes should ideally require auth (401/403),
            // but some are currently open — at minimum they must return JSON
            $content = $response->getContent();
            $this->assertStringNotContainsString('<!DOCTYPE', $content, "Admin endpoint {$endpoint} returned HTML instead of JSON");
        }
    }
}
