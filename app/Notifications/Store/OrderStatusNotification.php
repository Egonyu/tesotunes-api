<?php

namespace App\Notifications\Store;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected string $status;
    protected ?string $notes;

    /**
     * Create a new notification instance.
     */
    public function __construct($order, string $status, ?string $notes = null)
    {
        $this->order = $order;
        $this->status = $status;
        $this->notes = $notes;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting("Hello {$notifiable->display_name}!");

        $orderId = $this->order->order_number ?? $this->order->id;
        $total = number_format($this->order->total ?? 0);

        switch ($this->status) {
            case 'confirmed':
                $mail->subject("✅ Order #{$orderId} Confirmed!")
                     ->line("Your order has been confirmed and is being processed.")
                     ->line("**Order Total:** UGX {$total}")
                     ->action('Track Your Order', url("/store/orders/{$this->order->id}"));
                break;

            case 'processing':
                $mail->subject("📦 Order #{$orderId} Being Prepared")
                     ->line("Good news! Your order is now being prepared for shipment.")
                     ->action('Track Your Order', url("/store/orders/{$this->order->id}"));
                break;

            case 'shipped':
                $mail->subject("🚚 Order #{$orderId} Shipped!")
                     ->line("Your order is on its way!")
                     ->line("You can track your delivery using the link below.")
                     ->action('Track Delivery', url("/store/orders/{$this->order->id}"));
                break;

            case 'delivered':
                $mail->subject("✅ Order #{$orderId} Delivered")
                     ->line("Your order has been delivered. We hope you enjoy your purchase!")
                     ->line("If you have any issues, please contact our support team.")
                     ->action('Leave a Review', url("/store/orders/{$this->order->id}/review"));
                break;

            case 'cancelled':
                $mail->subject("❌ Order #{$orderId} Cancelled")
                     ->line("Your order has been cancelled.");
                if ($this->notes) {
                    $mail->line("**Reason:** {$this->notes}");
                }
                $mail->line("If you didn't request this cancellation, please contact support.");
                break;

            default:
                $mail->subject("Order #{$orderId} Status Update")
                     ->line("Your order status has been updated to: **{$this->status}**")
                     ->action('View Order', url("/store/orders/{$this->order->id}"));
        }

        return $mail->line('Thank you for shopping with TesoTunes Store!');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $icons = [
            'confirmed' => '✅',
            'processing' => '📦',
            'shipped' => '🚚',
            'delivered' => '✅',
            'cancelled' => '❌',
        ];

        return [
            'type' => 'order_status',
            'icon' => $icons[$this->status] ?? '📋',
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number ?? $this->order->id,
            'status' => $this->status,
            'notes' => $this->notes,
            'action_url' => url("/store/orders/{$this->order->id}"),
        ];
    }

    protected function getTitle(): string
    {
        $orderId = $this->order->order_number ?? $this->order->id;
        return match($this->status) {
            'confirmed' => "Order #{$orderId} Confirmed",
            'processing' => "Order Being Prepared",
            'shipped' => "Order Shipped!",
            'delivered' => "Order Delivered",
            'cancelled' => "Order Cancelled",
            default => "Order Status Updated",
        };
    }

    protected function getMessage(): string
    {
        return match($this->status) {
            'confirmed' => 'Your order has been confirmed and is being processed.',
            'processing' => 'Your order is being prepared for shipment.',
            'shipped' => 'Your order is on its way!',
            'delivered' => 'Your order has been delivered.',
            'cancelled' => 'Your order has been cancelled. ' . ($this->notes ?? ''),
            default => "Order status: {$this->status}",
        };
    }
}
