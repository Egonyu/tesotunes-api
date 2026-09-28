<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentObservabilityService;
use App\Services\Payment\ZengaPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentObservabilityController extends Controller
{
    public function __construct(
        protected PaymentObservabilityService $observability,
        protected ZengaPayService $zengaPay,
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

    public function providerBalance(): JsonResponse
    {
        $result = $this->zengaPay->getBalance();

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to read the ZengaPay balance.',
                'data' => ['available' => false],
            ], 502);
        }

        $balance = (float) ($result['balance'] ?? 0);
        $currency = (string) ($result['currency'] ?? 'UGX');
        $now = now();
        $last = DB::table('payment_provider_balance_snapshots')
            ->where('provider', 'zengapay')
            ->latest('captured_at')
            ->first();

        if (! $last
            || (float) $last->balance !== $balance
            || $now->diffInMinutes(Carbon::parse($last->captured_at)) >= 5) {
            DB::table('payment_provider_balance_snapshots')->insert([
                'provider' => 'zengapay',
                'currency' => $currency,
                'balance' => $balance,
                'captured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $history = DB::table('payment_provider_balance_snapshots')
            ->where('provider', 'zengapay')
            ->where('captured_at', '>=', $now->copy()->subDays(7))
            ->latest('captured_at')
            ->limit(48)
            ->get(['balance', 'currency', 'captured_at'])
            ->reverse()
            ->values();
        $dayStart = DB::table('payment_provider_balance_snapshots')
            ->where('provider', 'zengapay')
            ->where('captured_at', '>=', $now->copy()->subDay())
            ->oldest('captured_at')
            ->value('balance');

        return response()->json([
            'data' => [
                'available' => true,
                'provider' => 'zengapay',
                'balance' => $balance,
                'currency' => $currency,
                'captured_at' => $now->toIso8601String(),
                'change_24h' => $dayStart === null ? null : round($balance - (float) $dayStart, 2),
                'history' => $history,
            ],
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
