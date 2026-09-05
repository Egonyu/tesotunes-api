<?php

namespace App\Services\Store;

use App\Models\Commerce\Settlement;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Services\Commerce\SettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromotionSettlementService
{
    public function __construct(private readonly SettlementService $ledger) {}

    public function buildBreakdown(Order $order, Product $promotion, ?User $seller = null): array
    {
        $grossUgx = (float) ($order->paid_ugx ?: $order->total_ugx ?: $promotion->price_ugx ?: 0);
        $grossCredits = (int) ($order->paid_credits ?: $order->total_credits ?: $promotion->price_credits ?: 0);
        $platformFeeUgx = $grossUgx > 0 ? (float) ($promotion->store?->calculatePromotionFee($grossUgx) ?? 0) : 0.0;
        $platformFeeCredits = $grossCredits > 0
            ? (int) round($grossCredits * config('promotions.platform_fee_credits_rate', 0.15))
            : 0;

        return [
            'promotion_id' => $promotion->id,
            'store_id' => $promotion->store_id,
            'seller_user_id' => $seller?->id ?? $promotion->store?->user_id,
            'gross_ugx' => round($grossUgx, 2),
            'gross_credits' => $grossCredits,
            'platform_fee_ugx' => round(min($platformFeeUgx, $grossUgx), 2),
            'platform_fee_credits' => $platformFeeCredits,
            'seller_net_ugx' => round(max($grossUgx - $platformFeeUgx, 0), 2),
            'seller_net_credits' => max($grossCredits - $platformFeeCredits, 0),
            'status' => 'pending',
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Take payment from the buyer, or fail the surrounding transaction.
     *
     * Both promotion purchase paths used to call spendCredits() and discard
     * the result. UserCredit::spendCredits() locks the wallet, re-checks, and
     * returns null rather than throwing when the balance moved underneath —
     * so a concurrent purchase created an order marked PAID with no debit
     * behind it. The buyer got the promotion free and the promoter was owed
     * money nobody had taken. The UGX leg had the matching problem from the
     * other side: a bare decrement with no floor, which simply went negative.
     *
     * Must be called inside a transaction; a failure here has to roll the
     * order back with it.
     *
     * @throws \RuntimeException when the buyer cannot cover the charge
     */
    public function chargeBuyer(
        User $buyer,
        int $credits,
        float $ugx,
        string $source,
        string $description,
        array $metadata = []
    ): void {
        if ($credits > 0 && ! $buyer->spendCredits($credits, $source, $description, $metadata)) {
            throw new \RuntimeException('Your credit balance changed before this purchase completed. Nothing was charged.');
        }

        if ($ugx > 0) {
            $debited = User::query()
                ->whereKey($buyer->id)
                ->where('ugx_balance', '>=', $ugx)
                ->decrement('ugx_balance', $ugx);

            if ($debited === 0) {
                throw new \RuntimeException('Your wallet balance changed before this purchase completed. Nothing was charged.');
            }

            $buyer->refresh();
        }
    }

    /**
     * Why this order item cannot be released yet, or null when it can.
     *
     * The seller endpoint and the scheduled auto-release both ask this, so
     * the two can never disagree about what a releasable order looks like.
     */
    public function releaseBlockedReason(Order $order, OrderItem $orderItem): ?string
    {
        if ($orderItem->verification_status !== 'submitted') {
            return 'Payout can only be released after the buyer has submitted proof.';
        }

        if ($this->hasOpenDispute($orderItem)) {
            return 'This order has an open dispute and must be resolved before payout is released.';
        }

        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_COMPLETED], true)) {
            return 'This promotion order is already closed.';
        }

        if ($order->payment_status === Order::PAYMENT_REFUNDED) {
            return 'This promotion order has already been refunded.';
        }

        return null;
    }

    /**
     * A dispute is open when the snapshot says so, or when a reason was filed
     * and no resolution has been recorded against it.
     */
    public function hasOpenDispute(OrderItem $orderItem): bool
    {
        $snapshot = is_array($orderItem->product_snapshot ?? null) ? $orderItem->product_snapshot : [];
        $dispute = (array) data_get($snapshot, 'promotion_dispute', []);

        if (($dispute['state'] ?? null) === 'open') {
            return true;
        }

        return filled($orderItem->dispute_reason) && blank($dispute['resolved_at'] ?? null);
    }

    /**
     * Settle to the promoter and close the order, atomically.
     *
     * Callers must clear releaseBlockedReason() first. $verifiedByUserId is
     * null for the scheduled auto-release, which is what distinguishes an
     * automatic release from a seller's own acceptance in the audit trail.
     */
    public function releaseToSeller(Order $order, OrderItem $orderItem, ?int $verifiedByUserId): array
    {
        return DB::transaction(function () use ($order, $orderItem, $verifiedByUserId) {
            $settlement = $this->settleOrder($order, $orderItem);

            $orderItem->forceFill([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $verifiedByUserId,
                'rejection_reason' => null,
            ])->save();

            $order->forceFill([
                'status' => Order::STATUS_COMPLETED,
                'completed_at' => now(),
                'payment_status' => Order::PAYMENT_PAID,
            ])->save();

            return $settlement;
        });
    }

    public function settleOrder(Order $order, OrderItem $orderItem): array
    {
        $summary = $this->summarize($orderItem);
        if (($summary['status'] ?? 'pending') === 'settled') {
            return $summary;
        }

        $snapshot = is_array($orderItem->product_snapshot) ? $orderItem->product_snapshot : [];
        $breakdown = $summary['breakdown'] ?? [];

        $snapshot['promotion_settlement'] = array_merge($breakdown, [
            'status' => 'settled',
            'settled_at' => now()->toIso8601String(),
            'reversed_at' => null,
            'reversal_reason' => null,
        ]);

        $orderItem->forceFill([
            'product_snapshot' => $snapshot,
        ])->save();

        $this->recordLedgerSettlement($orderItem, $breakdown);

        return $this->summarize($orderItem->fresh());
    }

    public function reverseOrder(Order $order, OrderItem $orderItem, ?string $reason = null): array
    {
        $summary = $this->summarize($orderItem);
        $status = $summary['status'] ?? 'pending';

        if ($order->payment_status !== Order::PAYMENT_REFUNDED) {
            $buyer = $order->buyer;

            if ($buyer) {
                $paidCredits = (int) ($order->paid_credits ?? 0);
                $paidUgx = (float) ($order->paid_ugx ?? 0);

                if ($paidCredits > 0) {
                    $buyer->addCredits(
                        $paidCredits,
                        'promotion_refund',
                        "Promotion refund {$order->order_number}",
                        ['order_id' => $order->id, 'order_item_id' => $orderItem->id]
                    );
                }

                if ($paidUgx > 0) {
                    $buyer->increment('ugx_balance', $paidUgx);
                }
            }
        }

        $snapshot = is_array($orderItem->product_snapshot) ? $orderItem->product_snapshot : [];
        $breakdown = $summary['breakdown'] ?? [];

        $snapshot['promotion_settlement'] = array_merge($breakdown, [
            'status' => $status === 'settled' ? 'reversed' : 'cancelled',
            'settled_at' => data_get($breakdown, 'settled_at'),
            'reversed_at' => now()->toIso8601String(),
            'reversal_reason' => $reason,
        ]);

        $orderItem->forceFill([
            'product_snapshot' => $snapshot,
        ])->save();

        $this->reverseLedgerSettlement($orderItem, $reason);

        return $this->summarize($orderItem->fresh());
    }

    /**
     * Escrow semantics: the promoter's money enters the unified ledger only
     * when the buyer (or an admin resolving a dispute) accepts the delivery
     * proof — never at payment time. Idempotent via the ledger's source key.
     */
    private function recordLedgerSettlement(OrderItem $orderItem, array $breakdown): void
    {
        $beneficiary = User::find($breakdown['seller_user_id'] ?? null);

        if (! $beneficiary) {
            Log::warning('promotions.settlement.skipped_no_beneficiary', [
                'order_item_id' => $orderItem->id,
                'breakdown_seller_user_id' => $breakdown['seller_user_id'] ?? null,
            ]);

            return;
        }

        $this->ledger->record(
            beneficiary: $beneficiary,
            source: $orderItem,
            vertical: Settlement::VERTICAL_PROMOTIONS,
            kind: 'promo_service',
            amounts: [
                'gross_ugx' => (float) ($breakdown['gross_ugx'] ?? 0),
                'fee_ugx' => (float) ($breakdown['platform_fee_ugx'] ?? 0),
                'gross_credits' => (int) ($breakdown['gross_credits'] ?? 0),
                'fee_credits' => (int) ($breakdown['platform_fee_credits'] ?? 0),
            ],
            metadata: [
                'promotion_id' => $breakdown['promotion_id'] ?? null,
                'store_id' => $breakdown['store_id'] ?? null,
                'order_id' => $orderItem->order_id,
            ],
        );
    }

    private function reverseLedgerSettlement(OrderItem $orderItem, ?string $reason): void
    {
        $settlement = Settlement::query()
            ->where('source_type', $orderItem->getMorphClass())
            ->where('source_id', $orderItem->getKey())
            ->where('kind', 'promo_service')
            ->first();

        if (! $settlement || $settlement->status === Settlement::STATUS_REVERSED) {
            return;
        }

        if ($settlement->status === Settlement::STATUS_PAID_OUT) {
            Log::warning('promotions.settlement.reverse_after_payout_requires_adjustment', [
                'settlement_id' => $settlement->id,
                'order_item_id' => $orderItem->id,
            ]);

            return;
        }

        $this->ledger->reverse($settlement, $reason ?? 'promotion order reversed');
    }

    public function summarize(OrderItem $orderItem): array
    {
        $orderItem->loadMissing(['order.buyer', 'product.store.user']);

        $snapshot = is_array($orderItem->product_snapshot) ? $orderItem->product_snapshot : [];
        $breakdown = data_get($snapshot, 'promotion_settlement');

        if (! is_array($breakdown)) {
            $product = $orderItem->product;
            $order = $orderItem->order;

            if (! $product || ! $order) {
                return [
                    'status' => 'pending',
                    'breakdown' => [],
                ];
            }

            $breakdown = $this->buildBreakdown($order, $product, $product->store?->user);
        }

        return [
            'status' => (string) ($breakdown['status'] ?? 'pending'),
            'breakdown' => $breakdown,
            'settled_at' => $breakdown['settled_at'] ?? null,
            'reversed_at' => $breakdown['reversed_at'] ?? null,
            'reversal_reason' => $breakdown['reversal_reason'] ?? null,
        ];
    }
}
