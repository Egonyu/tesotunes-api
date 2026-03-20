<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use DatabaseTransactions;

    private string $webhookUrl = '/api/webhooks/zengapay';

    private string $webhookSecret = 'test-zengapay-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.zengapay.webhook_secret', $this->webhookSecret);
    }

    public function test_webhook_is_publicly_accessible(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'transactionId' => 'TXN-public-check',
            'transactionStatus' => 'SUCCESS',
        ]);

        $this->assertNotEquals(401, $response->status());
    }

    public function test_webhook_rejects_invalid_signature(): void
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
        $response->assertJson([
            'message' => 'Invalid signature',
        ]);
    }

    public function test_webhook_rejects_missing_transaction_reference_with_valid_signature(): void
    {
        $response = $this->postSignedWebhook([
            'transactionStatus' => 'SUCCESS',
            'amount' => 2000,
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Missing transaction identifier',
        ]);
    }

    public function test_webhook_returns_404_for_unknown_transaction_with_valid_signature(): void
    {
        $response = $this->postSignedWebhook([
            'transactionId' => 'TXN-does-not-exist',
            'externalReference' => 'REF-does-not-exist',
            'transactionStatus' => 'SUCCESS',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Payment not found',
        ]);
    }

    public function test_webhook_marks_matching_payment_completed(): void
    {
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'status' => Payment::STATUS_PROCESSING,
            'payment_reference' => 'REF-zenga-success',
            'transaction_reference' => 'REF-zenga-success',
            'provider_transaction_id' => null,
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
            'completed_at' => null,
        ]));

        $response = $this->postSignedWebhook([
            'transactionId' => 'TXN-zenga-success',
            'externalReference' => 'REF-zenga-success',
            'transactionStatus' => 'SUCCESS',
            'amount' => 2000,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Webhook processed',
            'payment_id' => $payment->id,
            'new_status' => Payment::STATUS_COMPLETED,
        ]);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->status);
        $this->assertSame('TXN-zenga-success', $payment->provider_transaction_id);
        $this->assertNotNull($payment->completed_at);
    }

    public function test_webhook_is_idempotent_for_completed_payment(): void
    {
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'status' => Payment::STATUS_COMPLETED,
            'payment_reference' => 'REF-zenga-done',
            'transaction_reference' => 'REF-zenga-done',
            'provider_transaction_id' => 'TXN-zenga-done',
            'payment_method' => Payment::METHOD_ZENGAPAY,
            'provider' => Payment::PROVIDER_ZENGAPAY,
            'payment_provider' => Payment::PROVIDER_ZENGAPAY,
        ]));

        $response = $this->postSignedWebhook([
            'transactionId' => 'TXN-zenga-done',
            'externalReference' => 'REF-zenga-done',
            'transactionStatus' => 'SUCCESS',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Payment already finalized',
        ]);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    private function postSignedWebhook(array $payload)
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $this->webhookSecret);

        return $this->call(
            'POST',
            $this->webhookUrl,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-ZengaPay-Signature' => $signature,
            ],
            $json
        );
    }
}
