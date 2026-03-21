<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
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
}
