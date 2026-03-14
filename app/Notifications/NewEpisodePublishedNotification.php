<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\PodcastEpisode;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewEpisodePublishedNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected PodcastEpisode $episode
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            [AppNotificationChannel::class, ExpoPushChannel::class],
            'podcast'
        );
    }

    public function toArray(object $notifiable): array
    {
        $podcast = $this->episode->podcast;

        return [
            'type' => 'new_episode',
            'module' => 'podcast',
            'episode_id' => $this->episode->id,
            'episode_title' => $this->episode->title,
            'podcast_id' => $podcast->id,
            'podcast_title' => $podcast->title,
            'title' => 'New Episode',
            'message' => "New episode of {$podcast->title}: \"{$this->episode->title}\"",
            'icon' => 'podcast',
            'color' => 'purple',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $podcast = $this->episode->podcast;

        return [
            'title' => 'New Episode',
            'body' => "{$podcast->title} — \"{$this->episode->title}\"",
            'data' => [
                'type' => 'new_episode',
                'episodeId' => $this->episode->id,
                'podcastId' => $podcast->id,
                'screen' => 'EpisodeDetail',
                'params' => ['episodeId' => $this->episode->id],
            ],
        ];
    }
}
