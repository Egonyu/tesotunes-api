<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ForumReplyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,

            // Flags
            'is_solution' => (bool) $this->is_solution,
            'is_highlighted' => (bool) $this->is_highlighted,

            // Stats
            'likes_count' => (int) ($this->likes_count ?? 0),

            // Author
            'author' => $this->when($this->relationLoaded('user') && $this->user, function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'avatar' => $this->user->avatar ? url('storage/'.$this->user->avatar) : null,
                ];
            }),

            // Parent (for nested replies)
            'parent_id' => $this->parent_id,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
