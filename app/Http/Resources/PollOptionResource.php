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
        return [
            'id' => $this->id,
            'text' => $this->option_text,
            'image' => $this->image ? StorageHelper::url($this->image) : null,
            'position' => $this->position,

            'song' => $this->when($this->song_id && $this->relationLoaded('song') && $this->song, fn () => [
                'id' => $this->song->id,
                'title' => $this->song->title,
                'artwork_url' => StorageHelper::artworkUrl($this->song->artwork),
                'artist' => $this->song->artist ? [
                    'id' => $this->song->artist->id,
                    'name' => $this->song->artist->stage_name,
                ] : null,
            ]),

            'artist' => $this->when($this->artist_id && $this->relationLoaded('artist') && $this->artist, fn () => [
                'id' => $this->artist->id,
                'name' => $this->artist->stage_name,
                'avatar_url' => StorageHelper::avatarUrl($this->artist->avatar, $this->artist->stage_name),
            ]),

            // Results — withheld until the respondent has answered or show_results_before_completion is true
            'response_count' => $this->when(static::$showResults, $this->response_count),
            'percentage' => $this->when(static::$showResults, fn () => $this->percentage),
        ];
    }
}
