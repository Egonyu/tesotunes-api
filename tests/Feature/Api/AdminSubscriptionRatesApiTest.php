<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class AdminSubscriptionRatesApiTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->where('key', 'platform_commissions')->delete();
        SubscriptionPlan::query()->delete();
        UserSubscription::query()->delete();

        $this->admin = $this->createUserWithRole('admin');
    }

    public function test_admin_can_fetch_subscription_rates_and_platform_commissions(): void
    {
        SubscriptionPlan::factory()->basic()->create([
            'metadata' => [
                'stream_rate_ugx' => '5.00',
                'credit_to_ugx_rate' => '1.25',
            ],
        ]);

        Setting::set('platform_commissions', [
            'streaming_percent' => 18,
            'subscription_percent' => 7.5,
            'credit_conversion_percent' => 2,
            'withdrawal_percent' => 3,
            'distribution_percent' => 12,
            'store_percent' => 6,
        ], Setting::TYPE_JSON, Setting::GROUP_PAYMENTS);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/subscriptions/rates');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.records.0.rates.stream_rate_ugx', '5.00')
            ->assertJsonPath('data.records.0.rates.credit_to_ugx_rate', '1.25')
            ->assertJsonPath('data.plans.0.rates.stream_rate_ugx', '5.00')
            ->assertJsonPath('data.platform_commissions.streaming_percent', '18.00')
            ->assertJsonPath('data.platform_commissions.subscription_percent', '7.50')
            ->assertJsonPath('data.export.format', 'csv');

        $this->assertStringContainsString('/api/admin/subscriptions/rates/export', (string) $response->json('data.export.url'));
        $this->assertStringContainsString('subscription_rates_', (string) $response->json('data.export.filename'));
    }

    public function test_admin_can_export_subscription_rates_as_csv(): void
    {
        SubscriptionPlan::factory()->basic()->create([
            'name' => 'Starter',
            'metadata' => [
                'stream_rate_ugx' => '5.00',
                'credit_to_ugx_rate' => '1.25',
            ],
        ]);

        Setting::set('platform_commissions', [
            'streaming_percent' => 18,
            'subscription_percent' => 7.5,
            'credit_conversion_percent' => 2,
            'withdrawal_percent' => 3,
            'distribution_percent' => 12,
            'store_percent' => 6,
        ], Setting::TYPE_JSON, Setting::GROUP_PAYMENTS);

        $response = $this->actingAs($this->admin)->get('/api/admin/subscriptions/rates/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition');

        $content = $response->getContent();

        $this->assertStringContainsString('Subscription Rates', $content);
        $this->assertStringContainsString('streaming_percent,18.00', $content);
        $this->assertStringContainsString('Starter', $content);
        $this->assertStringContainsString('5.00', $content);
        $this->assertStringContainsString('1.25', $content);
    }

    public function test_admin_can_fetch_main_subscription_list_with_filters_and_export_metadata(): void
    {
        $plan = SubscriptionPlan::factory()->premium()->create([
            'name' => 'Gold Plan',
            'slug' => 'gold-plan',
            'tier' => 'premium',
        ]);
        $user = $this->createUserWithRole('listener');
        $user->update([
            'name' => 'Alice Example',
            'username' => 'alice',
            'email' => 'alice@example.com',
        ]);

        UserSubscription::factory()->active()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'payment_method' => 'mobile_money',
            'amount_paid' => 25000,
            'started_at' => now()->subDays(3),
            'expires_at' => now()->addDays(12),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/subscriptions?status=active&search=alice&expiring_within_days=30');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.records.0.user.email', 'alice@example.com')
            ->assertJsonPath('data.records.0.plan.slug', 'gold-plan')
            ->assertJsonPath('data.filters.status', 'active')
            ->assertJsonPath('data.filters.search', 'alice')
            ->assertJsonPath('data.export.format', 'csv')
            ->assertJsonPath('data.export.filters.status', 'active');

        $this->assertStringContainsString('/api/admin/subscriptions/export?status=active&search=alice&expiring_within_days=30', (string) $response->json('data.export.url'));
        $this->assertStringContainsString('subscriptions_active_', (string) $response->json('data.export.filename'));
    }

    public function test_admin_can_export_main_subscription_list_as_csv(): void
    {
        $plan = SubscriptionPlan::factory()->basic()->create([
            'name' => 'Starter Plan',
            'slug' => 'starter-plan',
            'tier' => 'basic',
        ]);
        $user = $this->createUserWithRole('listener');
        $user->update([
            'name' => 'Brian Example',
            'username' => 'brian',
            'email' => 'brian@example.com',
        ]);

        UserSubscription::factory()->active()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'payment_method' => 'card',
            'amount_paid' => 12000,
            'started_at' => now()->subDays(1),
            'expires_at' => now()->addDays(20),
        ]);

        $response = $this->actingAs($this->admin)->get('/api/admin/subscriptions/export?status=active&search=brian');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition');

        $content = $response->getContent();

        $this->assertStringContainsString('Subscriptions', $content);
        $this->assertStringContainsString('Status,active', $content);
        $this->assertStringContainsString('Search,brian', $content);
        $this->assertStringContainsString('brian@example.com', $content);
        $this->assertStringContainsString('Starter Plan', $content);
        $this->assertStringContainsString('card', $content);
    }

    public function test_admin_can_bulk_update_subscription_rates_and_platform_commissions(): void
    {
        $basicPlan = SubscriptionPlan::factory()->basic()->create();
        $premiumPlan = SubscriptionPlan::factory()->premium()->create();

        $response = $this->actingAs($this->admin)->putJson('/api/admin/subscriptions/rates', [
            'plans' => [
                [
                    'id' => $basicPlan->id,
                    'stream_rate_ugx' => 4.5,
                    'credit_to_ugx_rate' => 1.1,
                ],
                [
                    'id' => $premiumPlan->id,
                    'stream_rate_ugx' => 12,
                    'credit_to_ugx_rate' => 1.75,
                ],
            ],
            'platform_commissions' => [
                'streaming_percent' => 15,
                'subscription_percent' => 5,
                'credit_conversion_percent' => 1.5,
                'withdrawal_percent' => 2.5,
                'distribution_percent' => 10,
                'store_percent' => 4,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.platform_commissions.streaming_percent', '15.00')
            ->assertJsonPath('data.platform_commissions.credit_conversion_percent', '1.50')
            ->assertJsonPath('data.records.0.id', $basicPlan->id);

        $basicPlan->refresh();
        $premiumPlan->refresh();

        $this->assertSame('4.50', $basicPlan->metadata['stream_rate_ugx']);
        $this->assertSame('1.1000', $basicPlan->metadata['credit_to_ugx_rate']);
        $this->assertSame('12.00', $premiumPlan->metadata['stream_rate_ugx']);
        $this->assertSame('1.7500', $premiumPlan->metadata['credit_to_ugx_rate']);
        $this->assertSame([
            'streaming_percent' => '15.00',
            'subscription_percent' => '5.00',
            'credit_conversion_percent' => '1.50',
            'withdrawal_percent' => '2.50',
            'distribution_percent' => '10.00',
            'store_percent' => '4.00',
        ], Setting::get('platform_commissions'));
    }

    public function test_subscription_plans_list_includes_rate_fields_for_admin_panel(): void
    {
        SubscriptionPlan::factory()->premium()->create([
            'metadata' => [
                'stream_rate_ugx' => '10.00',
                'credit_to_ugx_rate' => '1.5000',
            ],
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/subscription-plans');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.rates.stream_rate_ugx', '10.00')
            ->assertJsonPath('data.0.rates.credit_to_ugx_rate', '1.5000');
    }

    public function test_admin_can_update_a_single_plan_with_nested_rates(): void
    {
        $plan = SubscriptionPlan::factory()->premium()->create([
            'metadata' => [
                'stream_rate_ugx' => '8.00',
                'credit_to_ugx_rate' => '1.2000',
            ],
        ]);

        $response = $this->actingAs($this->admin)->putJson('/api/admin/subscription-plans/'.$plan->id, [
            'name' => 'Premium Plus',
            'price_monthly' => 55000,
            'rates' => [
                'stream_rate_ugx' => 11.5,
                'credit_to_ugx_rate' => 1.65,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Premium Plus')
            ->assertJsonPath('data.price_monthly', '55000.00')
            ->assertJsonPath('data.rates.stream_rate_ugx', '11.50')
            ->assertJsonPath('data.rates.credit_to_ugx_rate', '1.6500')
            ->assertJsonPath('data.rates.effective.effective_stream_rate_ugx', '11.50')
            ->assertJsonPath('data.rates.effective.estimated_net_per_stream_ugx', '9.78');

        $plan->refresh();

        $this->assertSame('Premium Plus', $plan->name);
        $this->assertSame('11.50', $plan->metadata['stream_rate_ugx']);
        $this->assertSame('1.6500', $plan->metadata['credit_to_ugx_rate']);
    }
}


