<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PodcastEpisodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'show_notes' => $this->show_notes,
            'episode_number' => $this->episode_number,
            'season_number' => $this->season_number,
            'episode_type' => $this->episode_type,
            'duration_seconds' => $this->duration,
            'duration_formatted' => $this->durationFormatted(),
            'file_size' => $this->file_size,
            'file_size_formatted' => $this->fileSizeFormatted(),
            'explicit' => $this->explicit,
            'is_premium' => $this->is_premium,
            'status' => $this->status,
            
            // Media URLs
            'audio_url' => $this->when($this->canAccess($request->user()), $this->audio_url),
            'artwork_url' => $this->artwork_url,
            
            // Technical specs
            'mime_type' => $this->mime_type,
            'bitrate' => $this->bitrate,
            'sample_rate' => $this->sample_rate,
            
            // Statistics
            'listen_count' => $this->listen_count,
            'download_count' => $this->download_count,
            'completion_rate' => $this->completion_rate,
            
            // Podcast info
            'podcast' => [
                'id' => $this->podcast->uuid,
                'title' => $this->podcast->title,
                'slug' => $this->podcast->slug,
                'cover_image_url' => $this->podcast->cover_image_url,
            ],
            
            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            
            // API URLs
            'links' => [
                'self' => route('api.episode.show', $this->uuid),
                'play' => route('api.episode.play', $this->uuid),
                'download' => route('api.episode.download', $this->uuid),
                'web' => route('podcast.episode.show', [$this->podcast->slug, $this->slug]),
            ],
        ];
    }

    /**
     * Check if user can access this episode.
     */
    protected function canAccess(?object $user): bool
    {
        // Public episodes are always accessible
        if (!$this->is_premium) {
            return true;
        }

        // Premium episodes require authentication
        if (!$user) {
            return false;
        }

        // Premium users can access all
        if ($user->subscription_tier === 'premium') {
            return true;
        }

        // Owners can access their own episodes
        if ($this->podcast->isOwnedBy($user)) {
            return true;
        }

        return false;
    }
}
