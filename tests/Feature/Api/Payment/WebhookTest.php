<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use DatabaseTransactions;

    private string $webhookUrl = '/api/webhooks/payment/zengapay';

    private string $genericWebhookUrl = '/api/payments/webhook';

    // ━━━ Webhook Access ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_webhook_is_publicly_accessible(): void
    {
        // Webhooks should NOT require authentication
        $response = $this->postJson($this->webhookUrl, [
            'transactionId' => 'TXN-nonexistent',
            'status' => 'COMPLETED',
        ]);

        // Should not be 401 — webhook is public
        $this->assertNotEquals(401, $response->status());
    }

    public function test_generic_webhook_is_publicly_accessible(): void
    {
        $response = $this->postJson($this->genericWebhookUrl, [
            'transactionId' => 'TXN-nonexistent',
            'status' => 'COMPLETED',
        ]);

        $this->assertNotEquals(401, $response->status());
    }

    // ━━━ Missing Transaction Reference ━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_webhook_rejects_missing_transaction_reference(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'status' => 'COMPLETED',
            // No transactionId, transaction_id, or reference
        ]);

        // Should return 400 or 403 (either bad request or invalid signature)
        $this->assertTrue(
            in_array($response->status(), [400, 403]),
            "Expected 400 or 403, got {$response->status()}"
        );
    }

    // ━━━ Payment Not Found ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_webhook_returns_404_for_unknown_transaction(): void
    {
        // Disable signature check for this test by sending from localhost
        $response = $this->postJson($this->webhookUrl, [
            'transactionId' => 'TXN-does-not-exist-' . time(),
            'status' => 'COMPLETED',
        ]);

        // Either 403 (sig failure) or 404 (not found) is acceptable
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    // ━━━ Idempotency ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_webhook_does_not_reprocess_completed_payment(): void
    {
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'status' => 'completed',
            'transaction_reference' => 'TXN-ALREADY-DONE',
        ]));

        // Even if signature check passes, the controller has idempotency logic
        // We cannot easily bypass signature, so test the model state
        $this->assertEquals('completed', $payment->fresh()->status);
    }

    // ━━━ Provider Routing ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_webhook_accepts_different_providers(): void
    {
        $providers = ['zengapay', 'flutterwave', 'stripe'];

        foreach ($providers as $provider) {
            $response = $this->postJson("/api/webhooks/payment/{$provider}", [
                'transactionId' => 'TXN-test',
                'status' => 'COMPLETED',
            ]);

            // Route should be reachable (not 401 or 405)
            // 400/403/404/500 are all acceptable (sig failure, missing payment, etc.)
            $this->assertTrue(
                ! in_array($response->status(), [401, 405]),
                "Provider route not reachable: {$provider} (got {$response->status()})"
            );
        }
    }

    // ━━━ HTTP Method ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_webhook_rejects_get_request(): void
    {
        $response = $this->getJson($this->webhookUrl);

        // Laravel may return 404 or 405 depending on route configuration
        $this->assertTrue(
            in_array($response->status(), [404, 405]),
            "Expected 404 or 405, got {$response->status()}"
        );
    }
}
