<?php

namespace App\Events\Podcast;

use App\Models\PodcastEpisode;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewEpisodePublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PodcastEpisode $episode;

    /**
     * Create a new event instance.
     */
    public function __construct(PodcastEpisode $episode)
    {
        $this->episode = $episode;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('podcast.' . $this->episode->podcast_id),
            new Channel('user.' . $this->episode->podcast->creator_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'episode.published';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'episode_id' => $this->episode->id,
            'podcast_id' => $this->episode->podcast_id,
            'title' => $this->episode->title,
            'slug' => $this->episode->slug,
            'podcast_title' => $this->episode->podcast->title,
            'podcast_slug' => $this->episode->podcast->slug,
            'duration' => $this->episode->duration,
            'published_at' => $this->episode->published_at->toIso8601String(),
        ];
    }
}
