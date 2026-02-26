<?php

namespace App\Notifications\Store;

use App\Modules\Store\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StorePaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $recipientType,
        protected Order $order,
        protected float $amount,
        protected string $paymentMethod,
        protected string $transactionId
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formattedAmount = number_format($this->amount);

        if ($this->recipientType === 'buyer') {
            return (new MailMessage)
                ->subject("Order Confirmed — #{$this->order->order_number}")
                ->greeting("Hello {$notifiable->display_name}!")
                ->line("Your payment of **UGX {$formattedAmount}** for order **#{$this->order->order_number}** has been confirmed.")
                ->line('**Order Details:**')
                ->line("- Order: #{$this->order->order_number}")
                ->line("- Amount: UGX {$formattedAmount}")
                ->line('- Payment: '.ucfirst($this->paymentMethod))
                ->line("- Transaction: {$this->transactionId}")
                ->action('View Order', url("/store/orders/{$this->order->id}"))
                ->line('Thank you for shopping on TesoTunes!');
        }

        return (new MailMessage)
            ->subject("New Sale — Order #{$this->order->order_number}")
            ->greeting("Hey {$notifiable->display_name}! 🎉")
            ->line("You just made a sale of **UGX {$formattedAmount}**!")
            ->line('**Sale Details:**')
            ->line("- Order: #{$this->order->order_number}")
            ->line("- Your Earnings: UGX {$formattedAmount}")
            ->line("- Transaction: {$this->transactionId}")
            ->action('View Sales', url('/store/seller/orders'))
            ->line('Keep up the great work!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => "store_payment_{$this->recipientType}",
            'module' => 'store',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'amount' => $this->amount,
            'payment_method' => $this->paymentMethod,
            'transaction_id' => $this->transactionId,
            'recipient_type' => $this->recipientType,
            'message' => $this->recipientType === 'buyer'
                ? "Payment confirmed for order #{$this->order->order_number}"
                : 'New sale of UGX '.number_format($this->amount)." — Order #{$this->order->order_number}",
        ];
    }
}
