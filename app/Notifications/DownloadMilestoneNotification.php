<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\Song;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DownloadMilestoneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Song $song,
        protected User $downloader,
        protected int $downloadCount
    ) {}

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class, ExpoPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'song_downloaded',
            'module' => 'music',
            'song_id' => $this->song->id,
            'song_title' => $this->song->title,
            'downloader_id' => $this->downloader->id,
            'downloader_name' => $this->downloader->name,
            'download_count' => $this->downloadCount,
            'title' => 'Song Downloaded',
            'message' => "{$this->downloader->name} downloaded \"{$this->song->title}\"",
            'icon' => 'download',
            'color' => 'green',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'New Download',
            'body' => "{$this->downloader->name} downloaded \"{$this->song->title}\"",
            'data' => [
                'type' => 'download',
                'songId' => $this->song->id,
                'downloadCount' => $this->downloadCount,
                'screen' => 'SongDetail',
                'params' => ['songId' => $this->song->id],
            ],
        ];
    }
}
