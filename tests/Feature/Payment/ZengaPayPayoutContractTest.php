<?php

namespace Tests\Feature\Payment;

use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the exact shape of the ZengaPay /transfers payload.
 *
 * Every payout ever attempted in production failed with
 * 422 {"use_contact":["The use contact field is required."]} because the
 * payload omitted that field. These tests pin the contract so it cannot
 * silently regress again.
 */
class ZengaPayPayoutContractTest extends TestCase
{
    private function adapter(): ZengaPayGatewayAdapter
    {
        config()->set('services.zengapay.api_key', 'test-key');
        config()->set('services.zengapay.base_url', 'https://api.zengapay.example/v1');

        return new ZengaPayGatewayAdapter;
    }

    public function test_payout_sends_use_contact_false_with_a_raw_msisdn(): void
    {
        Http::fake([
            '*/transfers' => Http::response(['transaction_id' => 'e5d3c1b2-0000-4000-8000-000000000000'], 200),
        ]);

        $this->adapter()->payout([
            'amount' => 5000,
            'phone' => '0772123456',
            'reference' => 'TT-W-ABC123',
            'description' => 'Payout',
        ]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/transfers')
                && array_key_exists('use_contact', $body)
                && $body['use_contact'] === false
                && $body['msisdn'] === '256772123456'
                && $body['amount'] === 5000
                && $body['external_reference'] === 'TT-W-ABC123';
        });
    }

    public function test_a_validation_failure_is_reported_in_the_provider_s_own_words(): void
    {
        Http::fake([
            '*/transfers' => Http::response(['use_contact' => ['The use contact field is required.']], 422),
        ]);

        $result = $this->adapter()->payout([
            'amount' => 5000,
            'phone' => '0772123456',
            'reference' => 'TT-W-ABC123',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('use contact field is required', $result['message']);
        $this->assertStringContainsString('422', $result['message']);
    }

    public function test_a_rate_limit_is_reported_as_such(): void
    {
        Http::fake([
            '*/transfers' => Http::response([
                'code' => 429,
                'status' => 'error',
                'message' => '429 Too Many Requests',
            ], 429),
        ]);

        $result = $this->adapter()->payout([
            'amount' => 5000,
            'phone' => '0772123456',
            'reference' => 'TT-W-ABC123',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['status']);
        // Told what to do, not handed the provider's status line.
        $this->assertStringContainsString('Wait a minute', $result['message']);
        $this->assertStringContainsString('nothing was charged', $result['message']);
    }

    public function test_the_provider_response_is_passed_back_for_persistence(): void
    {
        Http::fake([
            '*/transfers' => Http::response(['use_contact' => ['The use contact field is required.']], 422),
        ]);

        $result = $this->adapter()->payout([
            'amount' => 5000,
            'phone' => '0772123456',
            'reference' => 'TT-W-ABC123',
        ]);

        $this->assertNotNull($result['raw_response'] ?? null);
        $this->assertSame(
            ['The use contact field is required.'],
            $result['raw_response']['use_contact'] ?? null
        );
    }

    public function test_an_unhelpful_error_body_still_names_the_status(): void
    {
        Http::fake(['*/transfers' => Http::response([], 503)]);

        $result = $this->adapter()->payout([
            'amount' => 5000,
            'phone' => '0772123456',
            'reference' => 'TT-W-ABC123',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('503', $result['message']);
    }
}
