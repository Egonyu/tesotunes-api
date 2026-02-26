<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Models\Song;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SongUploadedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Song $song,
        protected User $artist
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_song',
            'module' => 'music',
            'song_id' => $this->song->id,
            'song_title' => $this->song->title,
            'artist_id' => $this->artist->id,
            'artist_name' => $this->artist->name,
            'artwork' => $this->song->artwork,
            'title' => 'New Music',
            'message' => "{$this->artist->name} released a new song: {$this->song->title}",
            'icon' => 'music-note',
            'color' => 'green',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'New Music',
            'body' => "{$this->artist->name} released \"{$this->song->title}\"",
            'data' => [
                'type' => 'music_update',
                'songId' => $this->song->id,
                'artistId' => $this->artist->id,
                'artistName' => $this->artist->name,
                'songTitle' => $this->song->title,
                'screen' => 'SongDetail',
                'params' => ['songId' => $this->song->id],
            ],
        ];
    }
}
