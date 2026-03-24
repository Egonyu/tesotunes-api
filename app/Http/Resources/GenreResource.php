<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // Emoji mapping for frontend display
        $emojiMap = [
            'akogo' => '🪕',
            'ateso-afrobeat' => '🎵',
            'teso-hip-hop' => '🎤',
            'urban-mainstream' => '🎧',
            'afrobeat' => '🥁',
            'hip-hop' => '🎤',
            'rap' => '🎤',
            'dancehall' => '💃',
            'reggae' => '🎸',
            'rnb' => '🎼',
            'soul' => '🎵',
            'pop' => '🎸',
            'rock' => '🎸',
            'jazz' => '🎷',
            'blues' => '🎺',
            'country' => '🤠',
            'folk' => '🪕',
            'gospel' => '🙏',
            'worship' => '🙏',
            'electronic' => '🎹',
            'edm' => '🎹',
            'house' => '🎹',
            'techno' => '🎹',
            'classical' => '🎻',
            'traditional' => '🥁',
            'tribal' => '🥁',
            'world' => '🌍',
        ];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'icon' => $this->icon,
            'emoji' => $emojiMap[$this->slug] ?? $this->icon ?? '🎵',
            'artwork_url' => $this->artwork_url,
            'song_count' => $this->whenCounted('songs', $this->songs_count ?? null),
            'is_active' => $this->is_active,

            // Conditional relationships
            'songs' => SongResource::collection($this->whenLoaded('songs')),
            'artists' => ArtistResource::collection($this->whenLoaded('artists')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => route('api.genres.show.slug', ['slug' => $this->slug]),
                'songs' => route('api.genres.songs', ['genre' => $this->id]),
                'artists' => route('api.genres.artists', ['genre' => $this->id]),
                'albums' => route('api.genres.albums', ['genre' => $this->id]),
            ],
        ];
    }
}
