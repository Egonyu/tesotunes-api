<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollOptionResource extends JsonResource
{
    public static bool $showResults = false;

    public function toArray(Request $request): array
    {
        $song = $this->whenLoaded('song');
        $artist = $this->whenLoaded('artist');

        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'image' => StorageHelper::url($this->image),
            'position' => $this->position,

            // Song context (for song_battle polls)
            'song' => $this->when(
                $this->song_id && $this->relationLoaded('song') && $this->song,
                fn () => [
                    'id' => $this->song->id,
                    'title' => $this->song->title,
                    'artwork_url' => $this->song->artwork_url,
                    'artist_name' => $this->song->artist?->stage_name,
                ]
            ),

            // Artist context (for artist_contest polls)
            'artist' => $this->when(
                $this->artist_id && $this->relationLoaded('artist') && $this->artist,
                fn () => [
                    'id' => $this->artist->id,
                    'stage_name' => $this->artist->stage_name,
                    'avatar_url' => $this->artist->avatar_url,
                    'is_verified' => (bool) $this->artist->is_verified,
                ]
            ),

            // Results — only shown when allowed
            'vote_count' => $this->when(static::$showResults, $this->vote_count),
            'percentage' => $this->when(static::$showResults, fn () => $this->percentage),
        ];
    }
}
