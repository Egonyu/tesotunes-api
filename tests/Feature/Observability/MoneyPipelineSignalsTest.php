<?php

namespace Tests\Feature\Observability;

use App\Models\Commerce\Settlement;
use App\Services\Commerce\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyPipelineSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reversing_a_settlement_emits_an_observability_signal(): void
    {
        $settlement = Settlement::factory()->cleared()->create();

        app(SettlementService::class)->reverse($settlement, 'refund issued');

        $this->assertDatabaseHas('observability_events', [
            'domain' => 'payments',
            'category' => 'payout',
            'outcome' => 'suspicious',
            'target_resource_type' => 'settlement',
            'target_resource_id' => (string) $settlement->id,
        ]);
    }

    public function test_clearing_a_non_pending_settlement_emits_a_failure_signal(): void
    {
        $settlement = Settlement::factory()->cleared()->create();

        // Clearing an already-reversed settlement is an illegal transition.
        $settlement->forceFill(['status' => Settlement::STATUS_REVERSED])->save();

        try {
            app(SettlementService::class)->clear($settlement);
            $this->fail('Expected a LogicException for an illegal clearance.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertDatabaseHas('observability_events', [
            'domain' => 'payments',
            'category' => 'settlement',
            'outcome' => 'failed',
            'severity' => 'high',
            'target_resource_type' => 'settlement',
            'target_resource_id' => (string) $settlement->id,
        ]);
    }
}
