<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZengaPayGatewayAdapterTest extends TestCase
{
    public function test_charge_parses_top_level_transaction_reference_from_collection_response(): void
    {
        config()->set('services.zengapay.api_key', 'test-api-key');
        config()->set('services.zengapay.base_url', 'https://api.zengapay.com/v1');

        Http::fake([
            'https://api.zengapay.com/v1/collections' => Http::response([
                'transactionReference' => '550e8400-e29b-41d4-a716-446655440000',
                'transactionExternalReference' => 'TT-UNIT-001',
                'message' => 'Prompt sent to customer',
            ], 200),
        ]);

        $adapter = new ZengaPayGatewayAdapter;

        $result = $adapter->charge([
            'amount' => 5000,
            'phone' => '0770000000',
            'reference' => 'TT-UNIT-001',
            'description' => 'Unit test payment',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['transaction_id']);
        $this->assertSame('TT-UNIT-001', $result['reference']);
        $this->assertSame('Prompt sent to customer', $result['message']);
        $this->assertIsArray($result['raw_response']);
    }
}
