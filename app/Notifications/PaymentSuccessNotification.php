<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentSuccessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Payment $payment
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) ($this->payment->amount ?? 0));
        $currency = $this->payment->currency ?? 'UGX';

        return (new MailMessage)
            ->subject('Payment Successful — TesoTunes')
            ->greeting("Hello {$notifiable->display_name}!")
            ->line("Your payment of **{$currency} {$amount}** has been processed successfully.")
            ->line('**Transaction Details:**')
            ->line("- Reference: {$this->payment->transaction_reference}")
            ->line("- Amount: {$currency} {$amount}")
            ->line('- Date: '.($this->payment->completed_at?->format('M d, Y H:i') ?? now()->format('M d, Y H:i')))
            ->line("- Method: ".ucfirst($this->payment->payment_method ?? 'Mobile Money'))
            ->action('View Transaction', url('/payments'))
            ->line('Thank you for using TesoTunes!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_success',
            'module' => 'payments',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency ?? 'UGX',
            'transaction_reference' => $this->payment->transaction_reference,
            'message' => 'Your payment of '.number_format((float) ($this->payment->amount ?? 0)).' has been processed successfully.',
        ];
    }
}
