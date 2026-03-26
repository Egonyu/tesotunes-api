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
        $displayName = trim((string) ($notifiable->display_name ?? $notifiable->name ?? ''));
        if ($displayName === '') {
            $displayName = 'there';
        }

        return match ($this->event) {
            self::NEW_LOGIN, self::NEW_DEVICE => (new MailMessage)
                ->subject('New Login Detected — TesoTunes')
                ->view('emails.security-alert', [
                    'brand' => 'TesoTunes',
                    'greeting' => "Hi {$displayName},",
                    'intro' => 'A new login was detected on your account:',
                    'details' => $this->normalizeDetails([
                        'Time' => $time,
                        'IP Address' => $ip,
                        'Device' => $userAgent,
                        'Location' => $location,
                    ]),
                    'body' => "If this wasn't you, please change your password immediately.",
                    'actionText' => 'Change Password',
                    'actionUrl' => $this->frontendUrl('/settings/security'),
                    'outro' => 'Stay safe!',
                ]),

            self::PASSWORD_CHANGED => (new MailMessage)
                ->subject('Password Changed — TesoTunes')
                ->view('emails.security-alert', [
                    'brand' => 'TesoTunes',
                    'greeting' => "Hi {$displayName},",
                    'intro' => 'Your password was successfully changed.',
                    'details' => $this->normalizeDetails([
                        'Time' => $time,
                        'IP Address' => $ip,
                    ]),
                    'body' => "If you didn't make this change, contact support immediately.",
                    'actionText' => 'Contact Support',
                    'actionUrl' => $this->frontendUrl('/support'),
                    'outro' => 'Security is our priority.',
                ]),

            self::SUSPICIOUS_ACTIVITY => (new MailMessage)
                ->subject('URGENT: Suspicious Activity — TesoTunes')
                ->view('emails.security-alert', [
                    'brand' => 'TesoTunes',
                    'greeting' => "Hi {$displayName},",
                    'intro' => 'We detected suspicious activity on your account.',
                    'details' => $this->normalizeDetails([
                        'Details' => ($this->metadata['details'] ?? 'Multiple failed login attempts'),
                        'Time' => $time,
                        'IP Address' => $ip,
                    ]),
                    'body' => 'For your security, we recommend changing your password.',
                    'actionText' => 'Secure Your Account',
                    'actionUrl' => $this->frontendUrl('/settings/security'),
                    'outro' => 'Stay alert and keep your account protected.',
                ]),

            default => (new MailMessage)
                ->subject('Security Alert — TesoTunes')
                ->view('emails.security-alert', [
                    'brand' => 'TesoTunes',
                    'greeting' => "Hi {$displayName},",
                    'intro' => 'A security event occurred on your account.',
                    'details' => $this->normalizeDetails([
                        'Time' => $time,
                    ]),
                    'body' => 'Review your recent activity and update your password if needed.',
                    'actionText' => 'Review Activity',
                    'actionUrl' => $this->frontendUrl('/settings/security'),
                    'outro' => 'Stay safe!',
                ]),
        };
    }

    private function frontendUrl(string $path): string
    {
        $raw = rtrim((string) config('app.frontend_url', ''), '/');
        $parts = parse_url($raw);

        $validFrontendUrl = is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);

        $base = $validFrontendUrl
            ? $raw
            : $this->derivedFrontendBaseUrl();

        return $base.'/'.ltrim($path, '/');
    }

    private function derivedFrontendBaseUrl(): string
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($appUrl);

        $validAppUrl = is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);

        if (! $validAppUrl) {
            return 'https://tesotunes.com';
        }

        $host = (string) $parts['host'];
        if (str_starts_with($host, 'api.')) {
            $host = substr($host, 4);
        }

        $base = strtolower((string) $parts['scheme']).'://'.$host;

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base;
    }

    private function normalizeDetails(array $details): array
    {
        $normalized = [];

        foreach ($details as $label => $value) {
            if ($value === null) {
                $normalized[$label] = 'N/A';

                continue;
            }

            if (is_scalar($value)) {
                $normalized[$label] = (string) $value;

                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            $normalized[$label] = $encoded !== false ? $encoded : 'N/A';
        }

        return $normalized;
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
