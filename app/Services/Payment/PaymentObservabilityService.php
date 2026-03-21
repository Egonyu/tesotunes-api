<?php

namespace App\Services\Payment;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PaymentObservabilityService
{
    public function buildDashboard(array $filters = []): array
    {
        $paymentsQuery = $this->paymentsBaseQuery($filters);
        $issuesQuery = $this->issuesBaseQuery($filters);

        $summary = [
            'total' => (clone $paymentsQuery)->count(),
            'completed' => (clone $paymentsQuery)->where('status', Payment::STATUS_COMPLETED)->count(),
            'processing' => (clone $paymentsQuery)->where('status', Payment::STATUS_PROCESSING)->count(),
            'pending' => (clone $paymentsQuery)->where('status', Payment::STATUS_PENDING)->count(),
            'failed' => (clone $paymentsQuery)->where('status', Payment::STATUS_FAILED)->count(),
            'cancelled' => (clone $paymentsQuery)->where('status', Payment::STATUS_CANCELLED)->count(),
            'refunded' => (clone $paymentsQuery)->where('status', Payment::STATUS_REFUNDED)->count(),
            'reversed' => (clone $paymentsQuery)->whereIn('status', [Payment::STATUS_CANCELLED, Payment::STATUS_REFUNDED])->count(),
            'open_issues' => (clone $issuesQuery)->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])->count(),
            'complaints' => (clone $issuesQuery)->where('issue_type', PaymentIssue::TYPE_CUSTOMER_COMPLAINT)->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])->count(),
            'invalid_webhook_signatures' => (clone $issuesQuery)->where('issue_type', PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE)->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])->count(),
            'missing_provider_reference' => (clone $issuesQuery)->where('issue_type', PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE)->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])->count(),
            'completed_amount' => round((float) ((clone $paymentsQuery)->where('status', Payment::STATUS_COMPLETED)->sum('amount') ?? 0), 2),
            'failed_amount' => round((float) ((clone $paymentsQuery)->where('status', Payment::STATUS_FAILED)->sum('amount') ?? 0), 2),
        ];

        $statusBreakdown = (clone $paymentsQuery)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ])
            ->values()
            ->all();

        $entryPointBreakdown = (clone $paymentsQuery)
            ->selectRaw("
                payment_type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as in_flight
            ")
            ->groupBy('payment_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'payment_type' => $row->payment_type,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'in_flight' => (int) $row->in_flight,
                'success_rate' => (int) $row->total > 0
                    ? round(((int) $row->completed / (int) $row->total) * 100, 1)
                    : 0,
            ])
            ->values()
            ->all();

        $issueBreakdown = (clone $issuesQuery)
            ->selectRaw('issue_type, status, COUNT(*) as count')
            ->groupBy('issue_type', 'status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'issue_type' => $row->issue_type,
                'status' => $row->status,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $recentAlerts = (clone $issuesQuery)
            ->with(['payment.user:id,name,email'])
            ->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (PaymentIssue $issue) => [
                'id' => $issue->id,
                'issue_type' => $issue->issue_type,
                'title' => $issue->title,
                'status' => $issue->status,
                'severity' => $issue->severity,
                'created_at' => optional($issue->created_at)->toIso8601String(),
                'payment' => $issue->payment ? [
                    'id' => $issue->payment->id,
                    'payment_type' => $issue->payment->payment_type,
                    'status' => $issue->payment->status,
                    'amount' => (float) $issue->payment->amount,
                    'payment_reference' => $issue->payment->payment_reference,
                ] : null,
                'user' => $issue->payment?->user ? [
                    'id' => $issue->payment->user->id,
                    'name' => $issue->payment->user->name,
                    'email' => $issue->payment->user->email,
                ] : null,
            ])
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'status_breakdown' => $statusBreakdown,
            'entry_point_breakdown' => $entryPointBreakdown,
            'issue_breakdown' => $issueBreakdown,
            'recent_alerts' => $recentAlerts,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function listPayments(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->paymentsBaseQuery($filters)
            ->with([
                'user:id,name,email',
                'issues' => fn ($query) => $query->latest(),
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function listIssues(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->issuesBaseQuery($filters)
            ->with([
                'payment.user:id,name,email',
                'resolver:id,name,email',
            ])
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    ELSE 4
                END
            ")
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['issue_type'])) {
            $query->where('issue_type', $filters['issue_type']);
        }

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (($filters['unresolved'] ?? null) !== null) {
            $unresolved = filter_var($filters['unresolved'], FILTER_VALIDATE_BOOLEAN);
            if ($unresolved) {
                $query->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED]);
            }
        }

        return $query->paginate($perPage);
    }

    public function paymentDetail(Payment $payment): array
    {
        $payment->loadMissing([
            'user:id,name,email',
            'issues.resolver:id,name,email',
        ]);

        $timeline = AuditLog::query()
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $payment->id)
            ->latest()
            ->get()
            ->map(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'created_at' => optional($entry->created_at)->toIso8601String(),
                'old_values' => $entry->old_values,
                'new_values' => $entry->new_values,
                'ip_address' => $entry->ip_address,
                'url' => $entry->url,
            ])
            ->values()
            ->all();

        return [
            'payment' => $this->serializePayment($payment),
            'issues' => $payment->issues->map(fn (PaymentIssue $issue) => $this->serializeIssue($issue))->values()->all(),
            'timeline' => $timeline,
        ];
    }

    public function entryPoints(): array
    {
        $stats = Payment::query()
            ->selectRaw("
                payment_type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as in_flight
            ")
            ->groupBy('payment_type')
            ->get()
            ->keyBy('payment_type');

        $issues = PaymentIssue::query()
            ->join('payments', 'payments.id', '=', 'payment_issues.payment_id')
            ->selectRaw("
                payments.payment_type,
                COUNT(*) as total_issues,
                SUM(CASE WHEN payment_issues.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_issues
            ")
            ->groupBy('payments.payment_type')
            ->get()
            ->keyBy('payment_type');

        $definitions = [
            [
                'key' => 'wallet_topup',
                'label' => 'Wallet Top-up',
                'payment_types' => ['wallet_topup'],
                'initiation_endpoints' => ['POST /api/payments/mobile-money/initiate'],
                'status_endpoints' => ['GET /api/payments/mobile-money/status/{reference}'],
                'webhook_endpoints' => ['POST /api/webhooks/zengapay'],
                'integration_mode' => 'zengapay_async',
                'observability' => 'full',
                'known_gap' => null,
                'notes' => 'Credits the user wallet after webhook or provider status confirmation.',
            ],
            [
                'key' => 'credits_purchase_mobile_money',
                'label' => 'Credits Purchase (Mobile Money)',
                'payment_types' => ['credits_purchase'],
                'initiation_endpoints' => ['POST /api/payments/mobile-money/initiate?purpose=credits_purchase'],
                'status_endpoints' => ['GET /api/payments/mobile-money/status/{reference}'],
                'webhook_endpoints' => ['POST /api/webhooks/zengapay'],
                'integration_mode' => 'zengapay_async',
                'observability' => 'full',
                'known_gap' => null,
                'notes' => 'Settles platform credits after payment completion.',
            ],
            [
                'key' => 'subscription_checkout',
                'label' => 'Subscription Checkout',
                'payment_types' => ['subscription'],
                'initiation_endpoints' => ['POST /api/payments/subscription', 'POST /api/subscriptions/subscribe'],
                'status_endpoints' => ['GET /api/payments/status/{lookup}'],
                'webhook_endpoints' => ['POST /api/webhooks/zengapay'],
                'integration_mode' => 'zengapay_async',
                'observability' => 'full',
                'known_gap' => null,
                'notes' => 'Subscription activates only after payment completion.',
            ],
            [
                'key' => 'wallet_withdrawal',
                'label' => 'Wallet Withdrawal',
                'payment_types' => ['withdrawal'],
                'initiation_endpoints' => ['POST /api/payments/wallet/withdraw'],
                'status_endpoints' => ['GET /api/payments/status/{lookup}'],
                'webhook_endpoints' => ['POST /api/webhooks/zengapay'],
                'integration_mode' => 'zengapay_disbursement',
                'observability' => 'full',
                'known_gap' => null,
                'notes' => 'Debits the wallet immediately and restores funds on failure/cancellation.',
            ],
            [
                'key' => 'event_ticket_purchase',
                'label' => 'Event Ticket Purchase',
                'payment_types' => ['ticket_purchase'],
                'initiation_endpoints' => ['POST /api/tickets/purchase'],
                'status_endpoints' => ['GET /api/payments/status/{lookup}'],
                'webhook_endpoints' => ['POST /api/webhooks/zengapay'],
                'integration_mode' => 'mixed',
                'observability' => 'full',
                'known_gap' => null,
                'notes' => 'Wallet and credits settle instantly; mobile money waits for asynchronous confirmation.',
            ],
            [
                'key' => 'store_order_checkout',
                'label' => 'Store Checkout',
                'payment_types' => [],
                'initiation_endpoints' => ['POST /api/store/payments/{orderNumber}/initiate'],
                'status_endpoints' => ['GET /api/store/payments/{orderNumber}/status'],
                'webhook_endpoints' => ['POST /api/store/webhooks/payment'],
                'integration_mode' => 'module_specific',
                'observability' => 'partial',
                'known_gap' => 'Store payments are tracked on orders, not in the core payments ledger used by this dashboard.',
                'notes' => 'Useful for operations mapping, but not yet rolled into the unified payment ledger.',
            ],
            [
                'key' => 'sacco_share_purchase',
                'label' => 'SACCO Share Purchase',
                'payment_types' => [],
                'initiation_endpoints' => ['POST /api/sacco/shares/buy', 'POST /api/sacco/shares/purchase'],
                'status_endpoints' => [],
                'webhook_endpoints' => [],
                'integration_mode' => 'wallet_only',
                'observability' => 'limited',
                'known_gap' => 'The frontend collects phone number and mobile-money method, but the controller currently settles directly and does not create a core Payment row or async status trail.',
                'notes' => 'This flow should be moved onto the unified payment ledger for production-grade traceability.',
            ],
            [
                'key' => 'sacco_savings_deposit',
                'label' => 'SACCO Savings Deposit',
                'payment_types' => [],
                'initiation_endpoints' => ['POST /api/sacco/savings/deposit'],
                'status_endpoints' => [],
                'webhook_endpoints' => [],
                'integration_mode' => 'wallet_only',
                'observability' => 'limited',
                'known_gap' => 'If the request does not use wallet settlement, the controller still posts the deposit without going through ZengaPay or the core payments ledger.',
                'notes' => 'Operationally high risk until routed through the shared payment lifecycle.',
            ],
            [
                'key' => 'sacco_loan_repayment',
                'label' => 'SACCO Loan Repayment',
                'payment_types' => [],
                'initiation_endpoints' => ['POST /api/sacco/loans/{loan}/repay', 'POST /api/sacco/loans/{loan}/pay'],
                'status_endpoints' => [],
                'webhook_endpoints' => [],
                'integration_mode' => 'manual_settlement',
                'observability' => 'limited',
                'known_gap' => 'Loan repayments are recorded directly on the loan without the shared payment ledger or webhook lifecycle.',
                'notes' => 'Best tracked today through SACCO loan records, not payment observability.',
            ],
        ];

        return collect($definitions)->map(function (array $definition) use ($stats, $issues) {
            $totals = [
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'in_flight' => 0,
                'open_issues' => 0,
                'total_issues' => 0,
            ];

            foreach ($definition['payment_types'] as $paymentType) {
                $paymentStats = $stats->get($paymentType);
                $issueStats = $issues->get($paymentType);

                $totals['total'] += (int) ($paymentStats->total ?? 0);
                $totals['completed'] += (int) ($paymentStats->completed ?? 0);
                $totals['failed'] += (int) ($paymentStats->failed ?? 0);
                $totals['in_flight'] += (int) ($paymentStats->in_flight ?? 0);
                $totals['open_issues'] += (int) ($issueStats->open_issues ?? 0);
                $totals['total_issues'] += (int) ($issueStats->total_issues ?? 0);
            }

            $definition['metrics'] = $totals;
            $definition['success_rate'] = $totals['total'] > 0
                ? round(($totals['completed'] / $totals['total']) * 100, 1)
                : null;

            return $definition;
        })->values()->all();
    }

    public function recordIssue(Payment $payment, string $type, string $title, array $attributes = []): PaymentIssue
    {
        $payload = [
            'title' => $title,
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? PaymentIssue::STATUS_OPEN,
            'severity' => $attributes['severity'] ?? 'medium',
            'money_deducted' => $attributes['money_deducted'] ?? false,
            'service_delivered' => $attributes['service_delivered'] ?? false,
            'provider_status' => $attributes['provider_status'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ];

        $issue = $payment->issues()
            ->where('issue_type', $type)
            ->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])
            ->latest()
            ->first();

        if ($issue) {
            $issue->fill($payload)->save();

            return $issue->refresh();
        }

        return $payment->issues()->create(array_merge($payload, [
            'issue_type' => $type,
        ]));
    }

    public function resolveIssue(Payment $payment, string $type, string $notes = '', string $resolutionType = PaymentIssue::RESOLUTION_AUTO_RESOLVED): void
    {
        $payment->issues()
            ->where('issue_type', $type)
            ->whereNotIn('status', [PaymentIssue::STATUS_RESOLVED, PaymentIssue::STATUS_CLOSED])
            ->get()
            ->each(fn (PaymentIssue $issue) => $issue->markAsResolved($resolutionType, $notes));
    }

    public function recordAudit(Payment $payment, string $action, array $newValues = [], array $oldValues = []): void
    {
        AuditLog::create([
            'user_id' => $payment->user_id,
            'action' => $action,
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'old_values' => empty($oldValues) ? null : $oldValues,
            'new_values' => empty($newValues) ? null : $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
        ]);
    }

    public function serializePayment(Payment $payment): array
    {
        $latestIssue = $payment->issues
            ->sortByDesc('created_at')
            ->first();

        return [
            'id' => $payment->id,
            'uuid' => $payment->uuid,
            'user' => $payment->user ? [
                'id' => $payment->user->id,
                'name' => $payment->user->name,
                'email' => $payment->user->email,
            ] : null,
            'payment_type' => $payment->payment_type,
            'payment_method' => $payment->payment_method,
            'provider' => $payment->payment_provider ?? $payment->provider,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'phone_number' => $payment->phone_number,
            'payment_reference' => $payment->payment_reference,
            'transaction_reference' => $payment->transaction_reference,
            'provider_transaction_id' => $payment->provider_transaction_id,
            'provider_reference' => $payment->provider_reference,
            'failure_reason' => $payment->failure_reason,
            'created_at' => optional($payment->created_at)->toIso8601String(),
            'initiated_at' => optional($payment->initiated_at)->toIso8601String(),
            'completed_at' => optional($payment->completed_at)->toIso8601String(),
            'failed_at' => optional($payment->failed_at)->toIso8601String(),
            'refunded_at' => optional($payment->refunded_at)->toIso8601String(),
            'processing_age_minutes' => $payment->initiated_at ? now()->diffInMinutes($payment->initiated_at) : null,
            'issue_count' => $payment->issues->count(),
            'latest_issue' => $latestIssue ? $this->serializeIssue($latestIssue) : null,
        ];
    }

    public function serializeIssue(PaymentIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'payment_id' => $issue->payment_id,
            'issue_type' => $issue->issue_type,
            'title' => $issue->title,
            'description' => $issue->description,
            'status' => $issue->status,
            'severity' => $issue->severity,
            'money_deducted' => (bool) $issue->money_deducted,
            'service_delivered' => (bool) $issue->service_delivered,
            'provider_status' => $issue->provider_status,
            'resolution_type' => $issue->resolution_type,
            'resolution_notes' => $issue->resolution_notes,
            'resolved_at' => optional($issue->resolved_at)->toIso8601String(),
            'created_at' => optional($issue->created_at)->toIso8601String(),
            'resolver' => $issue->resolver ? [
                'id' => $issue->resolver->id,
                'name' => $issue->resolver->name,
                'email' => $issue->resolver->email,
            ] : null,
            'payment' => $issue->relationLoaded('payment') && $issue->payment ? [
                'id' => $issue->payment->id,
                'payment_type' => $issue->payment->payment_type,
                'status' => $issue->payment->status,
                'amount' => (float) $issue->payment->amount,
                'payment_reference' => $issue->payment->payment_reference,
                'user' => $issue->payment->relationLoaded('user') && $issue->payment->user ? [
                    'id' => $issue->payment->user->id,
                    'name' => $issue->payment->user->name,
                    'email' => $issue->payment->user->email,
                ] : null,
            ] : null,
        ];
    }

    protected function paymentsBaseQuery(array $filters = []): Builder
    {
        $query = Payment::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        if (! empty($filters['provider'])) {
            $query->where(function (Builder $builder) use ($filters) {
                $builder->where('provider', $filters['provider'])
                    ->orWhere('payment_provider', $filters['provider']);
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('payment_reference', 'like', "%{$search}%")
                    ->orWhere('transaction_reference', 'like', "%{$search}%")
                    ->orWhere('provider_transaction_id', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    protected function issuesBaseQuery(array $filters = []): Builder
    {
        $query = PaymentIssue::query();

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }
}
