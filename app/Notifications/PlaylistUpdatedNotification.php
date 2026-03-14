<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\Playlist;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PlaylistUpdatedNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected Playlist $playlist,
        protected int $newSongsCount = 1
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            [AppNotificationChannel::class, ExpoPushChannel::class],
            'music'
        );
    }

    public function toArray(object $notifiable): array
    {
        $owner = $this->playlist->user;
        $suffix = $this->newSongsCount === 1
            ? 'a new song'
            : "{$this->newSongsCount} new songs";

        return [
            'type' => 'playlist_updated',
            'module' => 'music',
            'playlist_id' => $this->playlist->id,
            'playlist_name' => $this->playlist->name,
            'curator_name' => $owner?->display_name ?? $owner?->name,
            'new_songs_count' => $this->newSongsCount,
            'title' => 'Playlist Updated',
            'message' => "{$this->playlist->name} has {$suffix} added!",
            'icon' => 'music-note',
            'color' => 'blue',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $suffix = $this->newSongsCount === 1
            ? 'a new song'
            : "{$this->newSongsCount} new songs";

        return [
            'title' => 'Playlist Updated',
            'body' => "{$this->playlist->name} — {$suffix} added!",
            'data' => [
                'type' => 'playlist_updated',
                'playlistId' => $this->playlist->id,
                'screen' => 'PlaylistDetail',
                'params' => ['playlistId' => $this->playlist->id],
            ],
        ];
    }
}
