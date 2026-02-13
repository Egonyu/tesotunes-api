<?php

namespace App\Events\Podcast;

use App\Models\Podcast;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewPodcastPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Podcast $podcast;

    /**
     * Create a new event instance.
     */
    public function __construct(Podcast $podcast)
    {
        $this->podcast = $podcast;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('podcasts'),
            new Channel('user.' . $this->podcast->creator_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'podcast.published';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'podcast_id' => $this->podcast->id,
            'title' => $this->podcast->title,
            'slug' => $this->podcast->slug,
            'cover_image' => $this->podcast->cover_image_url,
            'creator_name' => $this->podcast->creator->display_name ?? $this->podcast->creator->name,
        ];
    }
}
