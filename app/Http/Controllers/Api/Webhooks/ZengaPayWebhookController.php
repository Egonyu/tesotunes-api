<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentObservabilityService;
use App\Services\Payment\ZengaPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The live ZengaPay callback entry point (POST /api/webhooks/zengapay).
 *
 * Note there are two other webhook handlers in the codebase; this is the one
 * the provider actually calls, which is why `payment_webhook_received` audits
 * from PaymentController never appeared while 52 signature failures did.
 */
class ZengaPayWebhookController extends Controller
{
    /** Headers the provider has used for the signature, most specific first. */
    private const SIGNATURE_HEADERS = [
        'X-ZengaPay-Signature',
        'X-Webhook-Signature',
        'X-Signature',
    ];

    public function __invoke(
        Request $request,
        ZengaPayService $service,
        PaymentObservabilityService $observability,
    ): JsonResponse {
        [$headerName, $signature] = $this->resolveSignature($request);
        $rawBody = $request->getContent();

        // Record arrival before verification, so the audit trail distinguishes
        // "never reached us" from "reached us and was rejected".
        $observability->recordWebhookAudit('payment_webhook_received', [
            'provider' => 'zengapay',
            'source' => 'zengapay_webhook_controller',
            'signature_header' => $headerName,
            'payload_keys' => array_keys($request->all()),
        ]);

        if (! $service->verifyWebhookSignature($rawBody, $signature, $request->all())) {
            $service->recordWebhookSignatureFailure($request->all(), $signature, $rawBody, $headerName);

            Log::warning('ZengaPay webhook: invalid signature', [
                'signature_header' => $headerName,
                'provided_signature' => $signature,
                'payload_keys' => array_keys($request->all()),
                'body_length' => strlen($rawBody),
            ]);

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $result = $service->handleWebhook($request->all());

        // 404 on an unmatched callback is deliberate: ZengaPay retries anything
        // outside 200/201/202, and the common cause is the payment row not being
        // committed yet when the callback lands — a case a retry does fix. The
        // audit below is here so those retries stop being invisible.
        if (! ($result['success'] ?? false)) {
            $observability->recordWebhookAudit('payment_webhook_unmatched', [
                'provider' => 'zengapay',
                'result' => $result,
            ]);

            return response()->json($result, 404);
        }

        return response()->json($result, 200);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveSignature(Request $request): array
    {
        foreach (self::SIGNATURE_HEADERS as $header) {
            $value = $request->header($header);

            if (is_string($value) && trim($value) !== '') {
                return [$header, $value];
            }
        }

        return [null, ''];
    }
}
