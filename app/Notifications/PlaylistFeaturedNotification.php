<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PlaylistFeaturedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $playlistId,
        protected string $playlistName
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'playlist_featured',
            'module' => 'music',
            'playlist_id' => $this->playlistId,
            'playlist_name' => $this->playlistName,
            'title' => 'Playlist Featured!',
            'message' => "Your playlist \"{$this->playlistName}\" has been featured!",
            'icon' => 'star',
            'color' => 'green',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'Playlist Featured! ⭐',
            'body' => "Your playlist \"{$this->playlistName}\" has been featured on TesoTunes!",
            'data' => [
                'type' => 'playlist_featured',
                'playlistId' => $this->playlistId,
                'screen' => 'Playlist',
                'params' => ['playlistId' => $this->playlistId],
            ],
        ];
    }
}
