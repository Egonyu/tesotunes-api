<?php

namespace App\Services\Store;

use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;

class PromotionSettlementService
{
    public function buildBreakdown(Order $order, Product $promotion, ?User $seller = null): array
    {
        $grossUgx = (float) ($order->paid_ugx ?: $order->total_ugx ?: $promotion->price_ugx ?: 0);
        $grossCredits = (int) ($order->paid_credits ?: $order->total_credits ?: $promotion->price_credits ?: 0);
        $platformFeeUgx = $grossUgx > 0 ? (float) ($promotion->store?->calculatePromotionFee($grossUgx) ?? 0) : 0.0;
        $platformFeeCredits = 0;

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

        return $this->summarize($orderItem->fresh());
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
