<?php

namespace App\Services\Store;

use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Services\CrossModuleNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells each side of a promotion order when it is their turn.
 *
 * Without these the hand-offs were silent: a promoter learned of an order
 * only by opening the workspace, and a buyer never heard that proof was
 * waiting — so the auto-release window ran out on buyers who didn't know to
 * look. Sending is best effort and never fails the money movement it follows.
 */
class PromotionOrderNotifier
{
    public function __construct(private readonly CrossModuleNotificationService $notifications) {}

    public function orderReceived(Order $order, ?User $promoter, string $serviceName): void
    {
        $this->send($promoter, 'order_received', 'New promotion order', "You have a new order for \"{$serviceName}\". Deliver it and send your proof.", $order, '/promoter/orders/'.$order->id, 'Open order');
    }

    public function deliverySubmitted(Order $order, ?User $buyer, string $serviceName): void
    {
        $this->send($buyer, 'promotion_delivered', 'Your promotion is ready to review', "The promoter sent proof for \"{$serviceName}\". Accept it or open a dispute.", $order, '/promotions/purchases/'.$order->id, 'Review proof');
    }

    public function deliveryAccepted(Order $order, ?User $promoter, string $serviceName, bool $automatic): void
    {
        $message = $automatic
            ? "The review window for \"{$serviceName}\" ended, so the order is complete. Your earnings move to your wallet after the dispute hold."
            : "The buyer accepted your delivery of \"{$serviceName}\". Your earnings move to your wallet after the dispute hold.";

        $this->send($promoter, 'promotion_accepted', 'Delivery accepted', $message, $order, '/promoter/orders/'.$order->id, 'View order');
    }

    public function orderDeclined(Order $order, ?User $buyer, string $serviceName, string $reason): void
    {
        $this->send($buyer, 'promotion_declined', 'Promotion order refunded', "The promoter couldn't deliver \"{$serviceName}\" and your payment was refunded. Reason: {$reason}", $order, '/promotions/purchases/'.$order->id, 'View order');
    }

    private function send(?User $recipient, string $type, string $title, string $message, Order $order, string $path, string $actionText): void
    {
        if (! $recipient) {
            return;
        }

        try {
            $this->notifications->sendToUser(
                $recipient,
                'store',
                $type,
                $title,
                $message,
                ['order_id' => $order->id, 'order_number' => $order->order_number],
                rtrim((string) config('app.frontend_url'), '/').$path,
                $actionText,
            );
        } catch (Throwable $e) {
            Log::warning('promotions.notification_failed', [
                'order_id' => $order->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
