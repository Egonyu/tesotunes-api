<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class, ExpoPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $displayName = trim((string) ($notifiable->display_name ?? $notifiable->name ?? 'there'));
        if ($displayName === '') {
            $displayName = 'there';
        }

        return (new MailMessage)
            ->subject('Welcome to TesoTunes!')
            ->greeting("Welcome, {$displayName}!")
            ->line('Thank you for joining TesoTunes — Africa\'s premier music platform.')
            ->line('Here\'s what you can do:')
            ->line('🎵 **Discover** — Stream thousands of African songs')
            ->line('❤️ **Connect** — Follow your favorite artists')
            ->line('📱 **Download** — Take your music offline')
            ->line('💰 **Earn** — Get credits for listening and engaging')
            ->action('Start Exploring', $this->frontendUrl('/discover'))
            ->line('Enjoy the music!');
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

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
            'module' => 'system',
            'title' => 'Welcome to TesoTunes!',
            'message' => 'Welcome aboard! Start discovering amazing African music.',
            'icon' => 'sparkles',
            'color' => 'green',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'Welcome to TesoTunes! 🎵',
            'body' => 'Start discovering amazing African music now.',
            'data' => [
                'type' => 'welcome',
                'screen' => 'Discover',
            ],
        ];
    }
}
