<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payment\ZengaPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZengaPayWebhookController extends Controller
{
    /**
     * Handle incoming ZengaPay payment webhook.
     */
    public function __invoke(Request $request, ZengaPayService $service): JsonResponse
    {
        // Verify webhook signature if configured
        $signature = $request->header('X-ZengaPay-Signature', '');
        if (! $service->verifyWebhookSignature($request->getContent(), $signature)) {
            Log::warning('ZengaPay webhook: invalid signature');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $result = $service->handleWebhook($request->all());

        $statusCode = ($result['success'] ?? false) ? 200 : 404;

        return response()->json($result, $statusCode);
    }
}
