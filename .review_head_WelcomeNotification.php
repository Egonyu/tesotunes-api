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
        return (new MailMessage)
            ->subject('Welcome to TesoTunes!')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line('Thank you for joining TesoTunes — Africa\'s premier music platform.')
            ->line('Here\'s what you can do:')
            ->line('🎵 **Discover** — Stream thousands of African songs')
            ->line('❤️ **Connect** — Follow your favorite artists')
            ->line('📱 **Download** — Take your music offline')
            ->line('💰 **Earn** — Get credits for listening and engaging')
            ->action('Start Exploring', url('/discover'))
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
