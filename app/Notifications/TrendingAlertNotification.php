<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\Song;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TrendingAlertNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected Song $song,
        protected string $genre,
        protected int $rank = 1
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
        $artist = $this->song->artist;

        return [
            'type' => 'trending_alert',
            'module' => 'music',
            'song_id' => $this->song->id,
            'song_title' => $this->song->title,
            'artist_name' => $artist?->name,
            'genre' => $this->genre,
            'rank' => $this->rank,
            'title' => 'Trending Now',
            'message' => "\"{$this->song->title}\" by {$artist?->name} is trending #{$this->rank} in {$this->genre}!",
            'icon' => 'trending-up',
            'color' => 'orange',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $artist = $this->song->artist;

        return [
            'title' => "Trending in {$this->genre}",
            'body' => "\"{$this->song->title}\" by {$artist?->name} is #{$this->rank}!",
            'data' => [
                'type' => 'trending_alert',
                'songId' => $this->song->id,
                'genre' => $this->genre,
                'screen' => 'SongDetail',
                'params' => ['songId' => $this->song->id],
            ],
        ];
    }
}
