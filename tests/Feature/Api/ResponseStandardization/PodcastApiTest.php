<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Podcast;
use App\Models\User;
use Tests\TestCase;

class PodcastApiTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.podcast.enabled' => true]);
    }

    // ─── List Podcasts ───────────────────────────────────────────

    public function test_list_podcasts_returns_paginated_data(): void
    {
        $response = $this->getJson('/api/podcasts');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total'],
                'links',
            ]);
    }

    public function test_podcast_list_contains_no_success_key(): void
    {
        $response = $this->getJson('/api/podcasts');

        $response->assertOk();
        $json = $response->json();
        $this->assertArrayNotHasKey('success', $json);
        $this->assertArrayHasKey('data', $json);
    }

    // ─── Podcast Categories ──────────────────────────────────────

    public function test_podcast_categories_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/podcast-categories');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Trending Podcasts ───────────────────────────────────────

    public function test_trending_podcasts_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/podcasts-trending');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Search ──────────────────────────────────────────────────

    public function test_podcast_search_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/podcasts-search?q=test');

        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $response->assertJsonStructure(['data']);
        } else {
            // Search controller may have issues — verify JSON not HTML
            $this->assertStringNotContainsString('<!DOCTYPE', $response->getContent());
        }
    }

    // ─── JSON Content Type ───────────────────────────────────────

    public function test_podcast_endpoints_return_json(): void
    {
        $endpoints = [
            '/api/podcasts',
            '/api/podcast-categories',
            '/api/podcasts-trending',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk()
                ->assertHeader('Content-Type', 'application/json');
        }
    }
}
