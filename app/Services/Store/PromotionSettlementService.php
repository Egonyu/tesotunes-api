<?php

namespace App\Services\Store;

use App\Models\ArtistRevenue;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use Illuminate\Support\Facades\DB;

class PromotionSettlementService
{
    public function buildBreakdown(Order $order, Product $promotion, ?User $seller = null): array
    {
        $commissionRate = $this->resolveCommissionRate($seller);
        $grossCredits = (int) ($order->total_credits ?? 0);
        $grossUgx = (float) ($order->total_ugx ?? $order->total_amount ?? 0);

        $platformFeeCredits = (int) round($grossCredits * $commissionRate / 100);
        $platformFeeUgx = round($grossUgx * $commissionRate / 100, 2);

        return [
            'commission_rate' => $commissionRate,
            'gross_credits' => $grossCredits,
            'gross_ugx' => round($grossUgx, 2),
            'platform_fee_credits' => $platformFeeCredits,
            'platform_fee_ugx' => $platformFeeUgx,
            'seller_net_credits' => max(0, $grossCredits - $platformFeeCredits),
            'seller_net_ugx' => max(0, round($grossUgx - $platformFeeUgx, 2)),
            'settlement_currency' => $order->payment_method,
            'promotion_id' => $promotion->id,
            'promotion_slug' => $promotion->slug,
        ];
    }

    public function settleOrder(Order $order, OrderItem $item): array
    {
        $promotion = $item->product;
        $seller = $promotion?->store?->user;
        if (! $promotion || ! $seller) {
            return [
                'success' => false,
                'message' => 'Promotion seller not found.',
            ];
        }

        $snapshot = $this->promotionSettlementSnapshot($item);
        $breakdown = $snapshot['breakdown'] ?? $this->buildBreakdown($order, $promotion, $seller);

        if (($snapshot['status'] ?? 'pending') === 'settled') {
            return [
                'success' => true,
                'message' => 'Promotion settlement already released.',
                'breakdown' => $breakdown,
            ];
        }

        DB::transaction(function () use ($order, $item, $seller, $promotion, $breakdown, $snapshot) {
            if (($breakdown['seller_net_credits'] ?? 0) > 0) {
                $seller->addCredits(
                    (float) $breakdown['seller_net_credits'],
                    'promotion_settlement',
                    "Promotion payout for {$order->order_number}",
                    [
                        'order_id' => $order->id,
                        'promotion_id' => $promotion->id,
                        'commission_rate' => $breakdown['commission_rate'] ?? null,
                    ]
                );
            }

            if (($breakdown['seller_net_ugx'] ?? 0) > 0) {
                if ($seller->artist) {
                    $artist = $seller->artist;
                    $artist->forceFill([
                        'earnings_balance' => (float) ($artist->earnings_balance ?? 0) + (float) $breakdown['seller_net_ugx'],
                        'total_revenue' => (float) ($artist->total_revenue ?? 0) + (float) $breakdown['seller_net_ugx'],
                    ])->save();

                    ArtistRevenue::create([
                        'artist_id' => $artist->id,
                        'revenue_type' => ArtistRevenue::TYPE_SALE,
                        'sourceable_type' => Order::class,
                        'sourceable_id' => $order->id,
                        'amount_ugx' => $breakdown['gross_ugx'] ?? 0,
                        'platform_fee' => $breakdown['platform_fee_ugx'] ?? 0,
                        'net_amount' => $breakdown['seller_net_ugx'] ?? 0,
                        'revenue_date' => now()->toDateString(),
                        'status' => ArtistRevenue::STATUS_CONFIRMED,
                        'notes' => "Promotion order {$order->order_number}",
                        'source_platform' => 'promotions',
                        'transaction_count' => 1,
                    ]);
                } else {
                    $seller->forceFill([
                        'ugx_balance' => (float) ($seller->ugx_balance ?? 0) + (float) $breakdown['seller_net_ugx'],
                    ])->save();
                }
            }

            $this->savePromotionSettlementSnapshot($item, $breakdown, 'settled', null);
        });

        return [
            'success' => true,
            'message' => 'Promotion settlement released successfully.',
            'breakdown' => $breakdown,
        ];
    }

    public function reverseOrder(Order $order, OrderItem $item, string $reason = ''): array
    {
        $promotion = $item->product;
        $seller = $promotion?->store?->user;
        if (! $promotion || ! $seller) {
            return [
                'success' => false,
                'message' => 'Promotion seller not found.',
            ];
        }

        $snapshot = $this->promotionSettlementSnapshot($item);
        $breakdown = $snapshot['breakdown'] ?? $this->buildBreakdown($order, $promotion, $seller);

        if (($snapshot['status'] ?? 'pending') !== 'settled') {
            $this->savePromotionSettlementSnapshot($item, $breakdown, 'reversed', $reason);

            return [
                'success' => true,
                'message' => 'Promotion settlement had not been released yet.',
                'breakdown' => $breakdown,
            ];
        }

        DB::transaction(function () use ($seller, $promotion, $order, $item, $breakdown, $reason) {
            if (($breakdown['seller_net_credits'] ?? 0) > 0) {
                $seller->spendCredits(
                    (float) $breakdown['seller_net_credits'],
                    'promotion_refund',
                    "Promotion reversal for {$order->order_number}",
                    [
                        'order_id' => $order->id,
                        'promotion_id' => $promotion->id,
                        'reason' => $reason,
                    ]
                );
            }

            if (($breakdown['seller_net_ugx'] ?? 0) > 0) {
                if ($seller->artist) {
                    $artist = $seller->artist;
                    $artist->forceFill([
                        'earnings_balance' => max(0, (float) ($artist->earnings_balance ?? 0) - (float) $breakdown['seller_net_ugx']),
                        'total_revenue' => max(0, (float) ($artist->total_revenue ?? 0) - (float) $breakdown['seller_net_ugx']),
                    ])->save();
                } else {
                    $seller->forceFill([
                        'ugx_balance' => max(0, (float) ($seller->ugx_balance ?? 0) - (float) $breakdown['seller_net_ugx']),
                    ])->save();
                }
            }

            $this->savePromotionSettlementSnapshot($item, $breakdown, 'reversed', $reason);
        });

        return [
            'success' => true,
            'message' => 'Promotion settlement reversed successfully.',
            'breakdown' => $breakdown,
        ];
    }

    public function summarize(OrderItem $item): array
    {
        $snapshot = $this->promotionSettlementSnapshot($item);

        return [
            'status' => $snapshot['status'] ?? 'pending',
            'breakdown' => $snapshot['breakdown'] ?? null,
            'reversal_reason' => $snapshot['reversal_reason'] ?? null,
            'settled_at' => $snapshot['settled_at'] ?? null,
            'reversed_at' => $snapshot['reversed_at'] ?? null,
        ];
    }

    private function resolveCommissionRate(?User $seller): float
    {
        $artistRate = (float) ($seller?->artist?->commission_rate ?? 0);
        $settingRate = (float) Setting::get('promotions_commission_rate', 18);
        $rate = $artistRate > 0 ? $artistRate : $settingRate;

        return max(0, min(100, $rate));
    }

    private function promotionSettlementSnapshot(OrderItem $item): array
    {
        $snapshot = $item->product_snapshot ?? [];
        $settlement = is_array($snapshot['promotion_settlement'] ?? null) ? $snapshot['promotion_settlement'] : [];

        return $settlement;
    }

    private function savePromotionSettlementSnapshot(OrderItem $item, array $breakdown, string $status, ?string $reason): void
    {
        $snapshot = $item->product_snapshot ?? [];
        $snapshot['promotion_settlement'] = array_filter([
            'status' => $status,
            'breakdown' => $breakdown,
            'settled_at' => $status === 'settled' ? now()->toIso8601String() : ($snapshot['promotion_settlement']['settled_at'] ?? null),
            'reversed_at' => $status === 'reversed' ? now()->toIso8601String() : ($snapshot['promotion_settlement']['reversed_at'] ?? null),
            'reversal_reason' => $reason,
        ], fn ($value) => $value !== null && $value !== '');

        $item->forceFill([
            'product_snapshot' => $snapshot,
        ])->save();
    }
}
