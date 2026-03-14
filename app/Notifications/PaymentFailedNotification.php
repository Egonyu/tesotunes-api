<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Payment $payment
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) ($this->payment->amount ?? 0));
        $currency = $this->payment->currency ?? 'UGX';
        $reason = $this->payment->failure_reason ?? 'An error occurred during processing';

        return (new MailMessage)
            ->subject('Payment Failed — TesoTunes')
            ->greeting("Hello {$notifiable->display_name},")
            ->line("Your payment of **{$currency} {$amount}** could not be completed.")
            ->line("**Reason:** {$reason}")
            ->line('**Transaction Details:**')
            ->line("- Reference: {$this->payment->transaction_reference}")
            ->line("- Amount: {$currency} {$amount}")
            ->line('- Method: '.ucfirst($this->payment->payment_method ?? 'Mobile Money'))
            ->action('Retry Payment', url('/payments'))
            ->line('If the issue persists, please contact support.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'module' => 'payments',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency ?? 'UGX',
            'transaction_reference' => $this->payment->transaction_reference,
            'failure_reason' => $this->payment->failure_reason,
            'message' => 'Your payment of '.number_format((float) ($this->payment->amount ?? 0)).' failed. Please try again.',
        ];
    }
}
