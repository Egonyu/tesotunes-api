<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Models\Podcast;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewPodcastPublishedNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected Podcast $podcast
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            ['database', ExpoPushChannel::class],
            'podcast'
        );
    }

    public function toArray(object $notifiable): array
    {
        $creator = $this->podcast->creator;

        return [
            'type' => 'new_podcast',
            'module' => 'podcast',
            'podcast_id' => $this->podcast->id,
            'podcast_title' => $this->podcast->title,
            'creator_id' => $creator?->id,
            'creator_name' => $creator?->display_name ?? $creator?->name,
            'title' => 'New Podcast',
            'message' => ($creator?->display_name ?? 'An artist')." launched a new podcast: \"{$this->podcast->title}\"",
            'icon' => 'podcast',
            'color' => 'purple',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $creator = $this->podcast->creator;

        return [
            'title' => 'New Podcast',
            'body' => ($creator?->display_name ?? 'An artist')." launched \"{$this->podcast->title}\"",
            'data' => [
                'type' => 'new_podcast',
                'podcastId' => $this->podcast->id,
                'screen' => 'PodcastDetail',
                'params' => ['podcastId' => $this->podcast->id],
            ],
        ];
    }
}
