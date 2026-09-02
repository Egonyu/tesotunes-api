<?php

namespace App\Services\Credits;

use App\Models\CreditIssue;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The observability layer for the credits ledger.
 *
 * Its reason for existing: four award paths spent four months quietly skipping
 * every account without a wallet row, and nothing anywhere noticed. The ledger
 * reconciled to the shilling the whole time, because a ledger records what
 * arrived and cannot describe what didn't. 875 credits of tips went missing in
 * plain sight.
 *
 * So the important method here is not recordIssue() — it is award(). Any layer
 * that depends on every caller remembering to report failure will eventually
 * meet a caller who doesn't. award() makes the reported path the *only* path:
 * credits either land, or an issue exists saying they didn't.
 */
class CreditObservabilityService
{
    /**
     * Award credits, and leave a record behind if they do not land.
     *
     * Returns the transaction on success and null on failure — the same shape
     * a caller already handles — but a null now always has a CreditIssue
     * standing behind it explaining itself.
     *
     * @param  array{metadata?: array<string, mixed>, sourceable?: Model|null, severity?: string, description?: string}  $options
     */
    public function award(
        User $user,
        float $amount,
        string $source,
        string $description,
        array $options = [],
    ): ?CreditTransaction {
        $metadata = $options['metadata'] ?? [];

        try {
            $transaction = $user->addCredits($amount, $source, $description, $metadata);

            if (! $transaction) {
                $this->recordIssue(
                    $user,
                    CreditIssue::TYPE_AWARD_FAILED,
                    "{$source} did not credit ".rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.')." to {$user->name}",
                    [
                        'source' => $source,
                        'amount' => $amount,
                        'severity' => $options['severity'] ?? 'high',
                        'description' => $options['description']
                            ?? 'The award returned no transaction, so nothing reached the wallet.',
                        'sourceable' => $options['sourceable'] ?? null,
                        'metadata' => $metadata,
                    ],
                );

                return null;
            }

            return $transaction;
        } catch (Throwable $e) {
            // The award threw. Record it rather than letting a catch block
            // upstream swallow it as "non-critical" — that judgement belongs to
            // whoever reads the issues queue, not to the code path that failed.
            Log::error('Credit award failed', [
                'user_id' => $user->id,
                'source' => $source,
                'amount' => $amount,
                'exception' => $e->getMessage(),
            ]);

            $this->recordIssue(
                $user,
                CreditIssue::TYPE_AWARD_FAILED,
                "{$source} threw while crediting {$user->name}",
                [
                    'source' => $source,
                    'amount' => $amount,
                    'severity' => $options['severity'] ?? 'high',
                    'description' => $e->getMessage(),
                    'sourceable' => $options['sourceable'] ?? null,
                    'metadata' => $metadata,
                ],
            );

            return null;
        }
    }

    /**
     * Open an issue, or update the one already open for this account and type.
     *
     * De-duplicated the same way payment issues are: a repeatedly failing award
     * should read as one unresolved problem, not a queue full of the same line.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordIssue(User $user, string $type, string $title, array $attributes = []): CreditIssue
    {
        $sourceable = $attributes['sourceable'] ?? null;

        $payload = [
            'title' => $title,
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? CreditIssue::STATUS_OPEN,
            'severity' => $attributes['severity'] ?? 'medium',
            'source' => $attributes['source'] ?? null,
            'amount' => $attributes['amount'] ?? 0,
            'credits_awarded' => $attributes['credits_awarded'] ?? false,
            'credit_transaction_id' => $attributes['credit_transaction_id'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'sourceable_type' => $sourceable instanceof Model ? $sourceable->getMorphClass() : null,
            'sourceable_id' => $sourceable instanceof Model ? $sourceable->getKey() : null,
        ];

        $existing = CreditIssue::query()
            ->where('user_id', $user->id)
            ->where('issue_type', $type)
            ->where('source', $payload['source'])
            ->unresolved()
            ->latest()
            ->first();

        if ($existing) {
            // Keep the running total rather than overwriting it: three failed
            // pool shares are 3× the debt, not the most recent one.
            $payload['amount'] = (float) $existing->amount + (float) $payload['amount'];
            $existing->fill($payload)->save();
            $existing->incrementAutoResolveAttempts();

            return $existing->refresh();
        }

        return CreditIssue::create(array_merge($payload, [
            'user_id' => $user->id,
            'issue_type' => $type,
        ]));
    }

    /**
     * Settle the open issues of a type for an account, once the credits have
     * actually landed.
     */
    public function resolveIssue(
        User $user,
        string $type,
        string $notes = '',
        string $resolutionType = CreditIssue::RESOLUTION_AUTO_RESOLVED,
    ): void {
        CreditIssue::query()
            ->where('user_id', $user->id)
            ->where('issue_type', $type)
            ->unresolved()
            ->get()
            ->each(fn (CreditIssue $issue) => $issue->markAsResolved($resolutionType, $notes));
    }

    /**
     * Wallets whose balance disagrees with the sum of their own transactions.
     *
     * Runs the reconciliation that was only ever done by hand. Raises a
     * BALANCE_DRIFT issue per drifting wallet rather than printing a report
     * nobody is subscribed to.
     *
     * @return array{checked: int, drifted: int}
     */
    public function detectBalanceDrift(bool $raiseIssues = true): array
    {
        $rows = DB::table('user_credits as w')
            ->leftJoin('credit_transactions as t', 't.user_id', '=', 'w.user_id')
            ->groupBy('w.user_id', 'w.balance')
            ->select('w.user_id', 'w.balance')
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'earned' THEN t.amount WHEN t.type = 'spent' THEN -t.amount ELSE 0 END), 0) as ledger")
            ->get();

        $drifted = 0;

        foreach ($rows as $row) {
            $delta = round((float) $row->balance - (float) $row->ledger, 2);

            if (abs($delta) < 0.01) {
                continue;
            }

            $drifted++;

            if (! $raiseIssues) {
                continue;
            }

            $user = User::find($row->user_id);

            if (! $user) {
                continue;
            }

            $this->recordIssue(
                $user,
                CreditIssue::TYPE_BALANCE_DRIFT,
                "Wallet balance disagrees with its transactions by {$delta}",
                [
                    'severity' => 'critical',
                    'amount' => abs($delta),
                    'description' => "Wallet holds {$row->balance}; its transactions sum to {$row->ledger}.",
                    'metadata' => [
                        'wallet_balance' => (float) $row->balance,
                        'ledger_sum' => (float) $row->ledger,
                        'delta' => $delta,
                    ],
                ],
            );
        }

        return ['checked' => $rows->count(), 'drifted' => $drifted];
    }

    /**
     * Accounts that can never be credited, because they have no wallet row.
     *
     * The condition that hid the tip losses. Reported rather than repaired:
     * creating a wallet is a write, and this method is for looking.
     *
     * @return array<int, int> user ids
     */
    public function accountsWithoutWallets(): array
    {
        return User::query()
            ->whereDoesntHave('creditWallet')
            ->pluck('id')
            ->all();
    }

    /**
     * What an operator needs to see on one screen.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $open = CreditIssue::query()->unresolved();

        return [
            'open_issues' => (clone $open)->count(),
            'high_severity' => (clone $open)->highSeverity()->count(),
            'credits_still_owed' => (float) CreditIssue::query()->stillOwed()->sum('amount'),
            'by_type' => CreditIssue::query()->unresolved()
                ->selectRaw('issue_type, count(*) as total, sum(amount) as amount')
                ->groupBy('issue_type')
                ->pluck('total', 'issue_type')
                ->all(),
            'by_source' => CreditIssue::query()->unresolved()
                ->whereNotNull('source')
                ->selectRaw('source, count(*) as total')
                ->groupBy('source')
                ->pluck('total', 'source')
                ->all(),
            'accounts_without_wallets' => count($this->accountsWithoutWallets()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listIssues(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return CreditIssue::query()
            ->with(['user:id,name,email', 'resolver:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('issue_type', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when(($filters['unresolved'] ?? false), fn ($q) => $q->unresolved())
            ->latest()
            ->paginate($perPage);
    }
}
