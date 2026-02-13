<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\User;
use Tests\TestCase;

class HealthCheckApiTest extends TestCase
{

    public function test_health_check_returns_json(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_detailed_health_check_returns_json(): void
    {
        $response = $this->getJson('/api/health/detailed');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }
}
