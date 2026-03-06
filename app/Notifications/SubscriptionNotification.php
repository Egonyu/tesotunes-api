<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const SUBSCRIBED = 'subscribed';

    public const RENEWED = 'renewed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const PAYMENT_FAILED = 'payment_failed';

    public const EXPIRING_SOON = 'expiring_soon';

    public function __construct(
        protected string $event,
        protected string $planName = '',
        protected array $metadata = []
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', ExpoPushChannel::class];

        if (in_array($this->event, [self::SUBSCRIBED, self::CANCELLED, self::PAYMENT_FAILED, self::EXPIRED, self::EXPIRING_SOON])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return match ($this->event) {
            self::SUBSCRIBED => (new MailMessage)
                ->subject('Welcome to TesoTunes Premium!')
                ->greeting("Hey {$notifiable->display_name}!")
                ->line("You've subscribed to **{$this->planName}**.")
                ->line('Enjoy unlimited downloads, 320kbps streaming, and ad-free listening.')
                ->action('Explore Premium', url('/premium'))
                ->line('Thank you for supporting African music!'),

            self::CANCELLED => (new MailMessage)
                ->subject('Subscription Cancelled — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line("Your **{$this->planName}** subscription has been cancelled.")
                ->line('You can continue using premium features until the end of your billing period.')
                ->line('Expires: '.($this->metadata['expires_at'] ?? 'N/A'))
                ->action('Resubscribe', url('/pricing'))
                ->line('We hope to see you back soon!'),

            self::PAYMENT_FAILED => (new MailMessage)
                ->subject('Payment Failed — TesoTunes Subscription')
                ->greeting("Hi {$notifiable->display_name},")
                ->line("We couldn't process your payment for **{$this->planName}**.")
                ->line('Please update your payment method to keep your subscription active.')
                ->action('Update Payment', url('/settings/billing'))
                ->line('Your subscription will be paused if payment is not resolved within 3 days.'),

            self::EXPIRING_SOON => (new MailMessage)
                ->subject('Your TesoTunes Subscription Expires Soon')
                ->greeting("Hi {$notifiable->display_name},")
                ->line("Your **{$this->planName}** subscription expires in **{$this->metadata['days_remaining']} day(s)**.")
                ->line($this->metadata['auto_renew'] ?? false
                    ? 'Don\'t worry — your subscription will auto-renew.'
                    : 'Enable auto-renew or resubscribe to keep your premium features.')
                ->action('Manage Subscription', url('/settings/subscription'))
                ->line('Thank you for supporting African music!'),

            default => (new MailMessage)
                ->subject('Subscription Update — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line($this->getEventMessage())
                ->action('View Subscription', url('/settings/subscription')),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => "subscription_{$this->event}",
            'module' => 'subscription',
            'event' => $this->event,
            'plan_name' => $this->planName,
            'title' => $this->getEventTitle(),
            'message' => $this->getEventMessage(),
            'metadata' => $this->metadata,
            'icon' => $this->getEventIcon(),
            'color' => $this->getEventColor(),
            'priority' => in_array($this->event, [self::PAYMENT_FAILED, self::EXPIRED]) ? 'high' : 'normal',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => $this->getEventTitle(),
            'body' => $this->getEventMessage(),
            'data' => [
                'type' => "subscription_{$this->event}",
                'planName' => $this->planName,
                'screen' => 'Subscription',
            ],
            'options' => [
                'priority' => in_array($this->event, [self::PAYMENT_FAILED]) ? 'high' : 'normal',
            ],
        ];
    }

    private function getEventTitle(): string
    {
        return match ($this->event) {
            self::SUBSCRIBED => 'Subscription Activated',
            self::RENEWED => 'Subscription Renewed',
            self::CANCELLED => 'Subscription Cancelled',
            self::EXPIRED => 'Subscription Expired',
            self::PAYMENT_FAILED => 'Payment Failed',
            self::EXPIRING_SOON => 'Subscription Expiring Soon',
            default => 'Subscription Update',
        };
    }

    private function getEventMessage(): string
    {
        return match ($this->event) {
            self::SUBSCRIBED => "Welcome to {$this->planName}! Enjoy premium features.",
            self::RENEWED => "Your {$this->planName} subscription has been renewed.",
            self::CANCELLED => "Your {$this->planName} subscription has been cancelled.",
            self::EXPIRED => "Your {$this->planName} subscription has expired. Resubscribe to continue.",
            self::PAYMENT_FAILED => "Payment failed for {$this->planName}. Please update your payment method.",
            self::EXPIRING_SOON => "Your {$this->planName} subscription expires in {$this->metadata['days_remaining']} day(s).",
            default => 'Your subscription status has changed.',
        };
    }

    private function getEventIcon(): string
    {
        return match ($this->event) {
            self::SUBSCRIBED, self::RENEWED => 'check-circle',
            self::CANCELLED => 'x-circle',
            self::EXPIRED => 'clock',
            self::EXPIRING_SOON => 'clock',
            self::PAYMENT_FAILED => 'exclamation-triangle',
            default => 'credit-card',
        };
    }

    private function getEventColor(): string
    {
        return match ($this->event) {
            self::SUBSCRIBED, self::RENEWED => 'green',
            self::CANCELLED => 'yellow',
            self::EXPIRING_SOON => 'yellow',
            self::EXPIRED, self::PAYMENT_FAILED => 'red',
            default => 'blue',
        };
    }
}
