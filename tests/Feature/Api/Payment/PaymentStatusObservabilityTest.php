<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Models\User;
use App\Services\Payment\ZengaPayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PaymentStatusObservabilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_status_endpoint_accepts_local_payment_id_and_records_missing_provider_reference_issue(): void
    {
        $user = User::factory()->create();

        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $user->id,
            'payment_type' => 'subscription',
            'status' => Payment::STATUS_PROCESSING,
            'payment_reference' => 'SUB-OBS-001',
            'transaction_reference' => 'SUB-OBS-001',
            'provider_transaction_id' => null,
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
            'initiated_at' => now()->subMinutes(5),
        ]));

        Sanctum::actingAs($user);

        $this
            ->getJson("/api/payments/status/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.status', Payment::STATUS_PROCESSING)
            ->assertJsonPath('data.issue_count', 1);

        $this->assertDatabaseHas('payment_issues', [
            'payment_id' => $payment->id,
            'issue_type' => PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
        ]);
    }

    public function test_status_poll_resolves_stale_signature_issue_when_payment_is_finalized(): void
    {
        $user = User::factory()->create();

        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $user->id,
            'payment_type' => 'wallet_topup',
            'status' => Payment::STATUS_PROCESSING,
            'amount' => 5000,
            'payment_reference' => 'TOPUP-OBS-002',
            'transaction_reference' => 'TOPUP-OBS-002',
            'provider_transaction_id' => '550e8400-e29b-41d4-a716-446655440099',
            'provider_reference' => 'TOPUP-OBS-002',
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
            'initiated_at' => now()->subMinutes(5),
        ]));

        $payment->issues()->create([
            'issue_type' => PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE,
            'title' => 'Invalid webhook signature',
            'status' => PaymentIssue::STATUS_OPEN,
            'severity' => 'critical',
        ]);

        $mock = Mockery::mock(ZengaPayService::class);
        $mock->shouldReceive('checkStatus')->once()->with('550e8400-e29b-41d4-a716-446655440099')->andReturn([
            'success' => true,
            'status' => Payment::STATUS_COMPLETED,
            'message' => 'Completed',
        ]);
        $this->app->instance(ZengaPayService::class, $mock);

        Sanctum::actingAs($user);

        $this
            ->getJson("/api/payments/status/{$payment->payment_reference}")
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_COMPLETED);

        $this->assertDatabaseHas('payment_issues', [
            'payment_id' => $payment->id,
            'issue_type' => PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE,
            'status' => PaymentIssue::STATUS_RESOLVED,
        ]);
    }
}
