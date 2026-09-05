<?php

namespace App\Console\Commands;

use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Release promotion escrow the seller never got round to accepting.
 *
 * Without this, a buyer's credits sit debited from them and uncredited to the
 * promoter indefinitely: proof is submitted, and nothing moves until the
 * seller clicks verify. config('promotions.auto_release_hours') has described
 * a 7-day window since the feature shipped and nothing read it.
 *
 * Only orders that would pass the seller's own release check are touched —
 * both ask PromotionSettlementService::releaseBlockedReason(), so an order
 * with an open dispute or missing proof is never swept up here.
 */
class ReleaseDuePromotionEscrow extends Command
{
    protected $signature = 'promotions:release-due-escrow {--dry-run : List what would be released without releasing it}';

    protected $description = 'Release promotion escrow whose auto-release window has passed';

    public function handle(PromotionSettlementService $settlements): int
    {
        $hours = (int) config('promotions.auto_release_hours', 168);

        if ($hours <= 0) {
            $this->warn('promotions.auto_release_hours is not a positive number — nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        $items = OrderItem::query()
            ->where('verification_status', 'submitted')
            ->whereNotNull('verification_submitted_at')
            ->where('verification_submitted_at', '<=', $cutoff)
            ->whereHas('product', fn ($query) => $query->promotion())
            ->whereHas('order', fn ($query) => $query
                ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_COMPLETED])
                ->where('payment_status', '!=', Order::PAYMENT_REFUNDED))
            ->with(['order.buyer', 'product.store.user'])
            ->get();

        if ($items->isEmpty()) {
            $this->info("No promotion escrow is due for release (window: {$hours}h).");

            return self::SUCCESS;
        }

        $released = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $order = $item->order;

            if (! $order) {
                $skipped++;

                continue;
            }

            if ($blocked = $settlements->releaseBlockedReason($order, $item)) {
                $this->line("  skip order {$order->id}: {$blocked}");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  would release order {$order->id} (submitted {$item->verification_submitted_at})");
                $released++;

                continue;
            }

            try {
                $settlements->releaseToSeller($order, $item, null);
                $this->line("  released order {$order->id}");
                $released++;
            } catch (Throwable $e) {
                Log::error('Promotion auto-release failed', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  failed order {$order->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $verb = $dryRun ? 'would release' : 'released';
        $this->info("Auto-release ({$hours}h window): {$verb} {$released}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
