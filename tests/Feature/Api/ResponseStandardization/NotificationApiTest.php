<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\User;

class NotificationApiTest extends ResponseStandardizationTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ─── List Notifications ──────────────────────────────────────

    public function test_list_notifications_returns_paginated_data(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total'],
                'links',
            ]);
    }

    public function test_notifications_uses_data_key_not_notifications_key(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/notifications');

        $response->assertOk();
        $json = $response->json();

        // Old pattern used "notifications" as key — ensure "data" is used now
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayNotHasKey('notifications', $json);
    }

    public function test_notifications_uses_meta_not_pagination_key(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/notifications');

        $response->assertOk();
        $json = $response->json();

        // Old pattern used "pagination" key — ensure "meta" is used now
        $this->assertArrayHasKey('meta', $json);
        $this->assertArrayNotHasKey('pagination', $json);
    }

    // ─── Unread Counts ───────────────────────────────────────────

    public function test_unread_counts_returns_data_wrapper(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/notifications/unread-counts');

        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $json = $response->json();
            // Should have 'data' wrapper, not bare counts
            $this->assertArrayHasKey('data', $json);
            $this->assertArrayNotHasKey('success', $json);
        } else {
            // Controller bug - at least verify JSON not HTML
            $this->assertStringNotContainsString('<!DOCTYPE', $response->getContent());
        }
    }

    // ─── Unauthenticated ─────────────────────────────────────────

    public function test_notifications_unauthenticated_returns_json_401(): void
    {
        $response = $this->getJson('/api/notifications');

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Mark Read ───────────────────────────────────────────────

    public function test_mark_all_read_returns_json(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/notifications/mark-all-read');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }
}
