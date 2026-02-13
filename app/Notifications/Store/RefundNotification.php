<?php

namespace App\Notifications\Store;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected float $amount;
    protected ?string $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct($order, float $amount, ?string $reason = null)
    {
        $this->order = $order;
        $this->amount = $amount;
        $this->reason = $reason;
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
        $orderId = $this->order->order_number ?? $this->order->id;
        $amount = number_format($this->amount);

        $mail = (new MailMessage)
            ->subject("💰 Refund Processed - Order #{$orderId}")
            ->greeting("Hello {$notifiable->display_name}!")
            ->line("A refund has been processed for your order #{$orderId}.")
            ->line("**Refund Amount:** UGX {$amount}");

        if ($this->reason) {
            $mail->line("**Reason:** {$this->reason}");
        }

        $mail->line('The refund will be credited to your original payment method within 3-5 business days.')
             ->action('View Order Details', url("/store/orders/{$this->order->id}"))
             ->line('If you have any questions, please contact our support team.');

        return $mail->line('Thank you for your patience!');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'refund',
            'icon' => '💰',
            'title' => 'Refund Processed',
            'message' => "A refund of UGX " . number_format($this->amount) . " has been processed for order #{$this->order->order_number}.",
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number ?? $this->order->id,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'action_url' => url("/store/orders/{$this->order->id}"),
        ];
    }
}
