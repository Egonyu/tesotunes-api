<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishSubscriptionPlansMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_canonical_plans_without_overwriting_admin_pricing(): void
    {
        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'emong',
            'price_monthly' => 4750,
            'is_active' => false,
            'is_visible' => false,
        ]);

        $migration = require database_path('migrations/2026_09_27_100000_publish_tesotunes_subscription_plans.php');
        $migration->up();

        $plan->refresh();

        $this->assertTrue($plan->is_active);
        $this->assertTrue($plan->is_visible);
        $this->assertSame('4750.00', $plan->price_monthly);
    }
}
