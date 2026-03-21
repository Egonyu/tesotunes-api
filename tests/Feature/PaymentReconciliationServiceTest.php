<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reconcile_stuck_does_not_query_provider_with_local_reference_when_provider_transaction_id_is_missing(): void
    {
        Http::fake();

        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'payment_type' => 'wallet_topup',
            'status' => Payment::STATUS_PROCESSING,
            'payment_reference' => 'TT-RECON-001',
            'transaction_reference' => 'TT-RECON-001',
            'provider_transaction_id' => null,
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
            'initiated_at' => now()->subMinutes(20),
        ]));

        $result = app(PaymentReconciliationService::class)->reconcileStuck();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['errors']);

        Http::assertNothingSent();

        $this->assertDatabaseHas('payment_issues', [
            'payment_id' => $payment->id,
            'issue_type' => PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
        ]);
    }
}
