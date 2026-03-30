<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Notifications\Concerns\BuildsFrontendUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable;

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
