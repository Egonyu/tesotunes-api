<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewFollowerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $follower,
        protected string $followableType = 'artist'
    ) {}

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class, ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_follower',
            'module' => 'social',
            'follower_id' => $this->follower->id,
            'follower_name' => $this->follower->name,
            'follower_avatar' => $this->follower->avatar_url,
            'followable_type' => $this->followableType,
            'title' => 'New Follower',
            'message' => "{$this->follower->name} started following you",
            'icon' => 'user-plus',
            'color' => 'blue',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'New Follower',
            'body' => "{$this->follower->name} started following you",
            'data' => [
                'type' => 'follow',
                'userId' => $this->follower->id,
                'userName' => $this->follower->name,
                'screen' => 'Profile',
                'params' => ['userId' => $this->follower->id],
            ],
        ];
    }
}
