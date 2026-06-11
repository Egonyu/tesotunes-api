<?php

namespace App\Services\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the unified settlement ledger.
 * Invariants enforced here, not trusted from callers:
 *   net = gross - fee, per currency
 *   one settlement per (source, beneficiary, kind)
 *   pending -> cleared -> paid_out; reversed only from pending/cleared
 */
class SettlementService
{
    /**
     * Record money owed to a beneficiary for a source transaction.
     * Idempotent: re-recording the same (source, beneficiary, kind) returns
     * the existing row untouched, so webhook retries can never double-settle.
     *
     * @param  array{gross_ugx?: float|string, fee_ugx?: float|string, gross_credits?: int, fee_credits?: int}  $amounts
     */
    public function record(
        User $beneficiary,
        Model $source,
        string $vertical,
        string $kind,
        array $amounts,
        ?Carbon $holdUntil = null,
        array $metadata = []
    ): Settlement {
        $grossUgx = round((float) ($amounts['gross_ugx'] ?? 0), 2);
        $feeUgx = round((float) ($amounts['fee_ugx'] ?? 0), 2);
        $grossCredits = (int) ($amounts['gross_credits'] ?? 0);
        $feeCredits = (int) ($amounts['fee_credits'] ?? 0);

        if ($grossUgx < 0 || $feeUgx < 0 || $grossCredits < 0 || $feeCredits < 0) {
            throw new \InvalidArgumentException('Settlement amounts must be non-negative.');
        }

        if ($feeUgx > $grossUgx || $feeCredits > $grossCredits) {
            throw new \InvalidArgumentException('Settlement fee cannot exceed the gross amount.');
        }

        $existing = Settlement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('beneficiary_user_id', $beneficiary->id)
            ->where('kind', $kind)
            ->first();

        if ($existing) {
            return $existing;
        }

        $settlement = new Settlement([
            'beneficiary_user_id' => $beneficiary->id,
            'vertical' => $vertical,
            'kind' => $kind,
            'hold_until' => $holdUntil ?? $this->defaultHoldUntil($vertical),
            'metadata' => $metadata ?: null,
        ]);

        $settlement->source()->associate($source);
        $settlement->forceFill([
            'gross_ugx' => $grossUgx,
            'fee_ugx' => $feeUgx,
            'net_ugx' => round($grossUgx - $feeUgx, 2),
            'gross_credits' => $grossCredits,
            'fee_credits' => $feeCredits,
            'net_credits' => $grossCredits - $feeCredits,
            'status' => Settlement::STATUS_PENDING,
        ]);
        $settlement->save();

        return $settlement;
    }

    /**
     * Promote all pending settlements whose hold window has passed.
     * Run by the commerce:clear-due-settlements scheduled command.
     */
    public function clearDue(?Carbon $asOf = null): int
    {
        return Settlement::query()
            ->dueForClearance($asOf)
            ->update([
                'status' => Settlement::STATUS_CLEARED,
                'cleared_at' => $asOf ?? now(),
            ]);
    }

    /**
     * Reverse a settlement (refund, upheld dispute). Allowed from pending or
     * cleared; a paid-out settlement requires a compensating adjustment, not
     * a reversal.
     */
    public function reverse(Settlement $settlement, string $reason): Settlement
    {
        if (! in_array($settlement->status, [Settlement::STATUS_PENDING, Settlement::STATUS_CLEARED], true)) {
            throw new \LogicException("Cannot reverse a settlement in status '{$settlement->status}'.");
        }

        $settlement->forceFill([
            'status' => Settlement::STATUS_REVERSED,
            'reversed_at' => now(),
            'reversal_reason' => $reason,
        ])->save();

        return $settlement;
    }

    /**
     * Attach cleared settlements to an executed payout and mark them paid out.
     *
     * @param  iterable<Settlement>  $settlements
     */
    public function markPaidOut(iterable $settlements, Model $payout): int
    {
        return DB::transaction(function () use ($settlements, $payout) {
            $count = 0;

            foreach ($settlements as $settlement) {
                if ($settlement->status !== Settlement::STATUS_CLEARED) {
                    throw new \LogicException(
                        "Only cleared settlements can be paid out (settlement {$settlement->id} is '{$settlement->status}')."
                    );
                }

                $settlement->payout()->associate($payout);
                $settlement->forceFill([
                    'status' => Settlement::STATUS_PAID_OUT,
                    'paid_out_at' => now(),
                ])->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * Balance summary for a beneficiary across statuses.
     *
     * @return array{pending: array{ugx: float, credits: int}, cleared: array{ugx: float, credits: int}, paid_out: array{ugx: float, credits: int}}
     */
    public function balances(User $user): array
    {
        $rows = Settlement::query()
            ->forBeneficiary($user)
            ->whereIn('status', [Settlement::STATUS_PENDING, Settlement::STATUS_CLEARED, Settlement::STATUS_PAID_OUT])
            ->selectRaw('status, COALESCE(SUM(net_ugx), 0) as ugx, COALESCE(SUM(net_credits), 0) as credits')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $summary = [];
        foreach ([Settlement::STATUS_PENDING, Settlement::STATUS_CLEARED, Settlement::STATUS_PAID_OUT] as $status) {
            $summary[$status] = [
                'ugx' => round((float) ($rows[$status]->ugx ?? 0), 2),
                'credits' => (int) ($rows[$status]->credits ?? 0),
            ];
        }

        return $summary;
    }

    private function defaultHoldUntil(string $vertical): ?Carbon
    {
        $days = (int) (config("commerce.settlement_hold_days.{$vertical}")
            ?? config('commerce.settlement_hold_days.default', 3));

        return $days > 0 ? now()->addDays($days) : null;
    }
}
