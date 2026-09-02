<?php

namespace Tests\Feature\Payment;

use App\Services\Payment\ZengaPayService;
use Tests\TestCase;

/**
 * ZengaPay signs transactionReference + msisdn + amount with the dashboard
 * webhook secret, HMAC-SHA256, hex encoded. Our verifier resolved the phone
 * from customerPhoneNumber/phoneNumber only, so that string was never among the
 * candidates and every production callback was rejected — 52 of them.
 */
class ZengaPayWebhookSignatureTest extends TestCase
{
    private const SECRET = 'test-webhook-secret-hash';

    private const REFERENCE = 'TT-GXR3M3DUMINM';

    private const MSISDN = '256772123456';

    private const AMOUNT = '5000';

    private function service(): ZengaPayService
    {
        config()->set('services.zengapay.webhook_secret', self::SECRET);
        config()->set('services.zengapay.webhook_secrets', []);
        config()->set('services.zengapay.api_secret', null);

        return new ZengaPayService;
    }

    /** The provider's documented envelope. */
    private function payload(): array
    {
        return [
            'event' => 'collection.success',
            'data' => [
                'transactionReference' => self::REFERENCE,
                'msisdn' => self::MSISDN,
                'amount' => self::AMOUNT,
                'transactionStatus' => 'SUCCESS',
            ],
        ];
    }

    private function documentedSignature(): string
    {
        return hash_hmac('sha256', self::REFERENCE.self::MSISDN.self::AMOUNT, self::SECRET);
    }

    public function test_the_documented_signature_is_accepted(): void
    {
        $payload = $this->payload();

        $this->assertTrue(
            $this->service()->verifyWebhookSignature(
                json_encode($payload),
                $this->documentedSignature(),
                $payload,
            ),
            'reference + msisdn + amount must verify — this is the scheme ZengaPay publishes.'
        );
    }

    public function test_a_wrong_signature_is_still_rejected(): void
    {
        $payload = $this->payload();

        $this->assertFalse(
            $this->service()->verifyWebhookSignature(
                json_encode($payload),
                hash_hmac('sha256', 'not-the-payload', self::SECRET),
                $payload,
            )
        );
    }

    public function test_a_signature_from_the_wrong_secret_is_rejected(): void
    {
        $payload = $this->payload();

        $this->assertFalse(
            $this->service()->verifyWebhookSignature(
                json_encode($payload),
                hash_hmac('sha256', self::REFERENCE.self::MSISDN.self::AMOUNT, 'someone-elses-secret'),
                $payload,
            )
        );
    }

    public function test_diagnostics_expose_the_expected_signature_for_comparison(): void
    {
        $payload = $this->payload();

        $diagnostics = $this->service()->signatureDiagnostics(json_encode($payload), $payload);

        $this->assertNotEmpty($diagnostics);

        $hashes = array_column($diagnostics, 'hmac_sha256_hex');
        $this->assertContains(
            $this->documentedSignature(),
            $hashes,
            'The documented signature must appear in diagnostics so a rejection can be compared.'
        );

        foreach ($diagnostics as $entry) {
            $this->assertArrayNotHasKey('secret', $entry, 'Diagnostics must never carry the secret.');
            $this->assertStringNotContainsString(self::SECRET, json_encode($entry));
        }
    }

    public function test_verification_still_fails_closed_without_a_secret_in_production(): void
    {
        config()->set('services.zengapay.webhook_secret', null);
        config()->set('services.zengapay.webhook_secrets', []);
        config()->set('services.zengapay.api_secret', null);
        app()->detectEnvironment(fn () => 'production');

        $payload = $this->payload();

        $this->assertFalse(
            (new ZengaPayService)->verifyWebhookSignature(json_encode($payload), 'anything', $payload)
        );
    }
}
