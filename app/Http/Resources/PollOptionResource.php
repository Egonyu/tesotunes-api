<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollOptionResource extends JsonResource
{
    /**
     * Whether to include vote counts / percentages.
     */
    public static bool $showResults = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'image' => $this->image ? url('storage/' . $this->image) : null,
            'position' => $this->position,

            // Results — only shown when allowed
            'vote_count' => $this->when(static::$showResults, $this->vote_count),
            'percentage' => $this->when(static::$showResults, fn () => $this->percentage),
        ];
    }
}
