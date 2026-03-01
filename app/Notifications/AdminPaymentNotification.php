<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminPaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Payment $payment,
        protected string $eventType = 'high_value'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) ($this->payment->amount ?? 0));
        $currency = $this->payment->currency ?? 'UGX';
        $user = $this->payment->user;

        $mail = (new MailMessage)
            ->greeting('Admin Alert');

        return match ($this->eventType) {
            'high_value' => $mail
                ->subject("[Admin] High-Value Payment: {$currency} {$amount}")
                ->line("A high-value payment of **{$currency} {$amount}** has been processed.")
                ->line('**Payment Details:**')
                ->line('- User: '.($user->display_name ?? 'Unknown').' ('.($user->email ?? 'N/A').')')
                ->line("- Amount: {$currency} {$amount}")
                ->line("- Reference: {$this->payment->transaction_reference}")
                ->line('- Status: '.ucfirst($this->payment->status))
                ->line('- Method: '.ucfirst($this->payment->payment_method ?? 'N/A'))
                ->action('Review Payment', url("/admin/payments/{$this->payment->id}")),

            'failed' => $mail
                ->subject("[Admin] Payment Failed: {$currency} {$amount}")
                ->line("A payment of **{$currency} {$amount}** has failed and may need investigation.")
                ->line('- User: '.($user->display_name ?? 'Unknown'))
                ->line('- Reason: '.($this->payment->failure_reason ?? 'Unknown'))
                ->line("- Reference: {$this->payment->transaction_reference}")
                ->action('Investigate', url("/admin/payments/{$this->payment->id}")),

            'refunded' => $mail
                ->subject("[Admin] Payment Refunded: {$currency} {$amount}")
                ->line("A payment of **{$currency} {$amount}** has been refunded.")
                ->line('- User: '.($user->display_name ?? 'Unknown'))
                ->line("- Reference: {$this->payment->transaction_reference}")
                ->action('View Details', url("/admin/payments/{$this->payment->id}")),

            default => $mail
                ->subject("[Admin] Payment Event: {$this->eventType}")
                ->line("Payment event ({$this->eventType}) for **{$currency} {$amount}**.")
                ->action('View Payment', url("/admin/payments/{$this->payment->id}")),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => "admin_payment_{$this->eventType}",
            'module' => 'admin',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency ?? 'UGX',
            'event_type' => $this->eventType,
            'user_id' => $this->payment->user_id,
            'transaction_reference' => $this->payment->transaction_reference,
            'message' => match ($this->eventType) {
                'high_value' => 'High-value payment: UGX '.number_format((float) ($this->payment->amount ?? 0)),
                'failed' => 'Payment failed: UGX '.number_format((float) ($this->payment->amount ?? 0)),
                'refunded' => 'Payment refunded: UGX '.number_format((float) ($this->payment->amount ?? 0)),
                default => "Payment event: {$this->eventType}",
            },
        ];
    }
}
