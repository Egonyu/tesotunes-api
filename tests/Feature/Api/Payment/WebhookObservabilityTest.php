<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookUrl = '/api/webhooks/zengapay';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.zengapay.webhook_secret', 'test-zengapay-webhook-secret');
    }

    public function test_invalid_webhook_signature_creates_audit_event_for_observability(): void
    {
        $payload = [
            'transactionId' => 'TXN-invalid-signature',
            'transactionStatus' => 'SUCCESS',
        ];

        $response = $this->call(
            'POST',
            $this->webhookUrl,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-ZengaPay-Signature' => 'bad-signature',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_webhook_signature_failed',
        ]);
    }

    public function test_successful_webhook_creates_webhook_audit_trail(): void
    {
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'status' => Payment::STATUS_PROCESSING,
            'payment_reference' => 'REF-zenga-observability',
            'transaction_reference' => 'REF-zenga-observability',
            'provider_transaction_id' => null,
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
            'completed_at' => null,
        ]));

        $payload = [
            'transactionId' => 'TXN-zenga-observability',
            'externalReference' => 'REF-zenga-observability',
            'transactionStatus' => 'SUCCESS',
            'amount' => 2000,
            'transactionCurrency' => 'UGX',
        ];

        $response = $this->call(
            'POST',
            $this->webhookUrl,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-ZengaPay-Signature' => $this->signatureForPayload($payload),
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_webhook_received',
            'auditable_id' => $payment->id,
        ]);
    }

    private function signatureForPayload(array $payload): string
    {
        $canonicalPayload = implode('', [
            $payload['transactionId'],
            $payload['transactionStatus'],
            (string) $payload['amount'],
            $payload['transactionCurrency'],
            $payload['externalReference'],
        ]);

        return hash_hmac('sha256', $canonicalPayload, 'test-zengapay-webhook-secret');
    }
}
