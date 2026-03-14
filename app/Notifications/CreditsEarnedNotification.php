<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CreditsEarnedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected float $amount,
        protected string $source,
        protected string $description,
        protected float $newBalance = 0
    ) {}

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class, ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'credits_earned',
            'module' => 'credits',
            'amount' => $this->amount,
            'source' => $this->source,
            'description' => $this->description,
            'new_balance' => $this->newBalance,
            'title' => 'Credits Earned',
            'message' => "You earned {$this->amount} credits: {$this->description}",
            'icon' => 'star',
            'color' => 'yellow',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'Credits Earned!',
            'body' => "+{$this->amount} credits — {$this->description}",
            'data' => [
                'type' => 'credits_earned',
                'amount' => $this->amount,
                'source' => $this->source,
                'newBalance' => $this->newBalance,
                'screen' => 'Wallet',
            ],
        ];
    }
}
