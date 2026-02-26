<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArtistRevenueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Payment $payment,
        protected string $eventType = 'revenue_received'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format($this->payment->amount);
        $currency = $this->payment->currency ?? 'UGX';

        return match ($this->eventType) {
            'revenue_received' => (new MailMessage)
                ->subject('Revenue Received — TesoTunes')
                ->greeting("Hey {$notifiable->display_name}! 🎵")
                ->line("You've earned **{$currency} {$amount}** in revenue!")
                ->line('**Revenue Details:**')
                ->line("- Amount: {$currency} {$amount}")
                ->line("- Type: ".ucfirst(str_replace('_', ' ', $this->payment->payment_type ?? 'streaming')))
                ->line('- Date: '.now()->format('M d, Y'))
                ->action('View Earnings', url('/artist/earnings'))
                ->line('Keep creating amazing music!'),

            'payout_failed' => (new MailMessage)
                ->subject('Payout Failed — TesoTunes')
                ->greeting("Hello {$notifiable->display_name},")
                ->line("Your payout of **{$currency} {$amount}** could not be processed.")
                ->line('**Reason:** '.($this->payment->failure_reason ?? 'Processing error'))
                ->line('Our team has been notified and will investigate.')
                ->action('View Payout Status', url('/artist/earnings'))
                ->line('Contact support if you need immediate assistance.'),

            default => (new MailMessage)
                ->subject('Revenue Update — TesoTunes')
                ->line("Revenue update for **{$currency} {$amount}**.")
                ->action('View Details', url('/artist/earnings')),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => "artist_{$this->eventType}",
            'module' => 'artist_revenue',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency ?? 'UGX',
            'event_type' => $this->eventType,
            'message' => match ($this->eventType) {
                'revenue_received' => 'You earned UGX '.number_format($this->payment->amount).' in revenue!',
                'payout_failed' => 'Your payout of UGX '.number_format($this->payment->amount).' failed.',
                default => 'Revenue update: UGX '.number_format($this->payment->amount),
            },
        ];
    }
}
