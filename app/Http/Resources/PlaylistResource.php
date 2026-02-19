<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaylistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            // Media
            'artwork_url' => $this->artwork_url,

            // Properties
            'visibility' => $this->visibility,
            'is_collaborative' => (bool) $this->is_collaborative,
            'is_featured' => (bool) $this->is_featured,
            'is_system' => (bool) $this->is_system,

            // Stats
            'song_count' => (int) ($this->song_count ?? $this->songs_count ?? 0),
            'total_duration_seconds' => (int) ($this->total_duration_seconds ?? 0),
            'play_count' => (int) ($this->play_count ?? 0),
            'follower_count' => (int) ($this->follower_count ?? 0),

            // Owner
            'owner' => $this->when($this->relationLoaded('owner') || $this->relationLoaded('user'), function () {
                $owner = $this->owner ?? $this->user;
                if (! $owner) {
                    return null;
                }

                return [
                    'id' => $owner->id,
                    'name' => $owner->name,
                ];
            }),

            // Conditional relationships
            'songs' => SongResource::collection($this->whenLoaded('songs')),

            // User-specific context (only when authenticated)
            'is_owner' => $this->when(auth()->check(), fn () => $this->user_id === auth()->id()),
            'can_edit' => $this->when(
                auth()->check() && method_exists($this->resource, 'canBeEditedBy'),
                fn () => $this->canBeEditedBy(auth()->user())
            ),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => url("/api/playlists/{$this->slug}"),
                'tracks' => url("/api/playlists/{$this->id}/tracks"),
            ],
        ];
    }
}
