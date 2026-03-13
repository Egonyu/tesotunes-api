<?php

namespace Tests\Feature\Api\Security;

use App\Http\Controllers\Api\Admin\AdminSubscriptionsController;
use App\Http\Controllers\Api\Admin\CampaignsApiController;
use App\Http\Controllers\Api\Admin\SaccoApiController;
use App\Http\Controllers\Api\Admin\StoreApiController;
use App\Http\Controllers\Api\Admin\DistributionPerformanceController;
use App\Http\Controllers\Api\PaymentController;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class PrivilegedControllerAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private function requestForUser(User $user, array $payload = []): Request
    {
        $request = Request::create('/internal-security-check', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_payment_analytics_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = app(PaymentController::class)->analytics($this->requestForUser($user));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_artist_payout_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = app(PaymentController::class)->artistPayout($this->requestForUser($user));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_distribution_performance_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = app(DistributionPerformanceController::class)->performance($this->requestForUser($user));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_admin_subscriptions_stats_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $request = $this->requestForUser($user);
        $response = app(AdminSubscriptionsController::class)->stats($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_store_admin_analytics_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = app(StoreApiController::class)->analytics($this->requestForUser($user));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_sacco_admin_stats_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = app(SaccoApiController::class)->stats($this->requestForUser($user));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_campaign_admin_stats_rejects_non_admin_inside_controller(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = app(CampaignsApiController::class)->stats($this->requestForUser($user));

        $this->assertSame(403, $response->getStatusCode());
    }
}
