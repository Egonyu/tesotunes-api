<?php

namespace Tests\Feature\Dashboard;

use App\Models\Commerce\Settlement;
use App\Models\PlayHistory;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression cover for the account dashboard.
 *
 * Each test here pins a query that was reading a column the schema does not
 * populate, and so reported a silent zero on every account.
 */
class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_plays_inside_the_window_are_counted(): void
    {
        $user = User::factory()->create();

        PlayHistory::factory()->count(3)->create([
            'user_id' => $user->id,
            'played_at' => now()->subDays(3),
        ]);

        $overview = app(DashboardService::class)->overview($user);

        $this->assertSame(3, $overview['listening']['plays_30d']);
        $this->assertSame(3, $overview['listening']['plays_total']);
    }

    public function test_plays_outside_the_window_are_excluded_but_still_count_all_time(): void
    {
        $user = User::factory()->create();

        PlayHistory::factory()->create([
            'user_id' => $user->id,
            'played_at' => now()->subDays(2),
        ]);
        PlayHistory::factory()->count(2)->create([
            'user_id' => $user->id,
            'played_at' => now()->subDays(45),
        ]);

        $overview = app(DashboardService::class)->overview($user);

        $this->assertSame(1, $overview['listening']['plays_30d']);
        $this->assertSame(3, $overview['listening']['plays_total']);
    }

    /**
     * play_histories.created_at is never written — the model maps CREATED_AT to
     * played_at — so any window query against it silently matches nothing.
     */
    public function test_play_history_rows_carry_no_created_at_to_filter_on(): void
    {
        $user = User::factory()->create();

        PlayHistory::factory()->create([
            'user_id' => $user->id,
            'played_at' => now()->subDay(),
        ]);

        $this->assertSame(
            0,
            PlayHistory::query()->whereNotNull('created_at')->count(),
            'created_at is populated after all — the dashboard may filter on it again.'
        );
    }

    public function test_earnings_report_a_credit_only_ledger(): void
    {
        $user = User::factory()->create();
        $source = User::factory()->create();

        $settlement = new Settlement([
            'beneficiary_user_id' => $user->id,
            'vertical' => Settlement::VERTICAL_CONTRIBUTIONS,
            'kind' => 'translation_accepted',
        ]);
        $settlement->source()->associate($source);
        $settlement->forceFill([
            'gross_ugx' => 0,
            'fee_ugx' => 0,
            'net_ugx' => 0,
            'gross_credits' => 200,
            'fee_credits' => 0,
            'net_credits' => 200,
            'status' => Settlement::STATUS_CLEARED,
            'cleared_at' => now(),
        ]);
        $settlement->save();

        $overview = app(DashboardService::class)->overview($user);

        $this->assertSame(200, $overview['earnings']['available']['credits']);
        $this->assertSame(0.0, $overview['earnings']['available']['ugx']);
    }

    public function test_overview_endpoint_returns_the_wallet_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'wallet' => ['ugx_balance', 'credits_balance'],
                    'earnings' => ['pending', 'available', 'paid_out'],
                    'listening' => ['plays_total', 'plays_30d'],
                    'capabilities',
                ],
            ]);
    }

    public function test_overview_endpoint_rejects_guests(): void
    {
        $this->getJson('/api/dashboard/overview')->assertUnauthorized();
    }
}
