<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentObservabilityController extends Controller
{
    public function __construct(
        protected PaymentObservabilityService $observability,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->observability->buildDashboard($this->filters($request)),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $paginator = $this->observability->listPayments(
            $this->filters($request),
            $this->perPage($request)
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Payment $payment) => $this->observability->serializePayment($payment))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function issues(Request $request): JsonResponse
    {
        $paginator = $this->observability->listIssues(
            $this->filters($request),
            $this->perPage($request)
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($issue) => $this->observability->serializeIssue($issue))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(string $payment): JsonResponse
    {
        $model = Payment::query()
            ->where('id', $payment)
            ->orWhere('uuid', $payment)
            ->firstOrFail();

        return response()->json([
            'data' => $this->observability->paymentDetail($model),
        ]);
    }

    public function entryPoints(): JsonResponse
    {
        return response()->json([
            'data' => $this->observability->entryPoints(),
        ]);
    }

    protected function filters(Request $request): array
    {
        return $request->only([
            'status',
            'payment_type',
            'provider',
            'search',
            'date_from',
            'date_to',
            'issue_type',
            'severity',
            'unresolved',
        ]);
    }

    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
