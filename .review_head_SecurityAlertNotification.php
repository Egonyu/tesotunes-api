<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const NEW_LOGIN = 'new_login';

    public const PASSWORD_CHANGED = 'password_changed';

    public const NEW_DEVICE = 'new_device';

    public const SUSPICIOUS_ACTIVITY = 'suspicious_activity';

    public function __construct(
        protected string $event,
        protected array $metadata = []
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [AppNotificationChannel::class, 'mail'];

        if ($this->event !== self::NEW_LOGIN) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ip = $this->metadata['ip'] ?? 'Unknown';
        $userAgent = $this->metadata['user_agent'] ?? 'Unknown device';
        $time = $this->metadata['time'] ?? now()->format('M d, Y H:i');
        $location = $this->metadata['location'] ?? 'Unknown location';

        return match ($this->event) {
            self::NEW_LOGIN, self::NEW_DEVICE => (new MailMessage)
                ->subject('New Login Detected — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line('A new login was detected on your account:')
                ->line("- **Time**: {$time}")
                ->line("- **IP Address**: {$ip}")
                ->line("- **Device**: {$userAgent}")
                ->line("- **Location**: {$location}")
                ->line("If this wasn't you, please change your password immediately.")
                ->action('Change Password', url('/settings/security'))
                ->line('Stay safe!'),

            self::PASSWORD_CHANGED => (new MailMessage)
                ->subject('Password Changed — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line('Your password was successfully changed.')
                ->line("- **Time**: {$time}")
                ->line("- **IP Address**: {$ip}")
                ->line("If you didn't make this change, contact support immediately.")
                ->action('Contact Support', url('/support')),

            self::SUSPICIOUS_ACTIVITY => (new MailMessage)
                ->subject('URGENT: Suspicious Activity — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line('We detected suspicious activity on your account.')
                ->line('Details: '.($this->metadata['details'] ?? 'Multiple failed login attempts'))
                ->line('For your security, we recommend changing your password.')
                ->action('Secure Your Account', url('/settings/security')),

            default => (new MailMessage)
                ->subject('Security Alert — TesoTunes')
                ->line('A security event occurred on your account.')
                ->action('Review Activity', url('/settings/security')),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => "security_{$this->event}",
            'module' => 'security',
            'event' => $this->event,
            'title' => $this->getEventTitle(),
            'message' => $this->getEventMessage(),
            'metadata' => $this->metadata,
            'icon' => 'shield-exclamation',
            'color' => $this->event === self::PASSWORD_CHANGED ? 'green' : 'red',
            'priority' => 'high',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => $this->getEventTitle(),
            'body' => $this->getEventMessage(),
            'data' => [
                'type' => "security_{$this->event}",
                'screen' => 'SecuritySettings',
            ],
            'options' => [
                'priority' => 'high',
            ],
        ];
    }

    private function getEventTitle(): string
    {
        return match ($this->event) {
            self::NEW_LOGIN => 'New Login',
            self::NEW_DEVICE => 'New Device Login',
            self::PASSWORD_CHANGED => 'Password Changed',
            self::SUSPICIOUS_ACTIVITY => 'Suspicious Activity',
            default => 'Security Alert',
        };
    }

    private function getEventMessage(): string
    {
        $ip = $this->metadata['ip'] ?? '';
        $suffix = $ip ? " from {$ip}" : '';

        return match ($this->event) {
            self::NEW_LOGIN => "New login detected on your account{$suffix}",
            self::NEW_DEVICE => "Login from a new device{$suffix}",
            self::PASSWORD_CHANGED => 'Your password was successfully changed',
            self::SUSPICIOUS_ACTIVITY => 'Suspicious activity detected on your account',
            default => 'A security event occurred',
        };
    }
}
