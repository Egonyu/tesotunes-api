<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_the_three_editable_tesotunes_packages(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $expected = [
            'emong' => [3000, 25000, 10],
            'eris' => [5000, 10000, 50],
            'engatuny' => [15000, 5000, 500],
        ];

        foreach ($expected as $slug => [$price, $withdrawalMinimum, $uploads]) {
            $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();

            $this->assertSame(number_format($price, 2, '.', ''), $plan->price_monthly);
            $this->assertSame($withdrawalMinimum, $plan->entitlement('finance.withdrawal_minimum_ugx'));
            $this->assertSame($uploads, $plan->entitlement('creator.uploads_per_month'));
            $this->assertTrue($plan->is_active);
            $this->assertTrue($plan->is_visible);
        }

        $this->assertSame(
            4,
            SubscriptionPlan::whereIn('slug', ['free', 'emong', 'eris', 'engatuny'])
                ->where('is_active', true)
                ->where('is_visible', true)
                ->count()
        );
    }

    public function test_rerunning_it_does_not_overwrite_admin_adjustments(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where('slug', 'eris')->firstOrFail();
        $entitlements = $plan->entitlements;
        $entitlements['finance.withdrawal_minimum_ugx'] = 42000;

        $plan->update([
            'price_monthly' => 7500,
            'entitlements' => $entitlements,
        ]);

        $this->seed(SubscriptionPlanSeeder::class);

        $plan->refresh();
        $this->assertSame('7500.00', $plan->price_monthly);
        $this->assertSame(42000, $plan->entitlement('finance.withdrawal_minimum_ugx'));
    }
}
