<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ForumThreadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'status' => $this->status,

            // Flags
            'is_pinned' => (bool) $this->is_pinned,
            'is_locked' => (bool) $this->is_locked,
            'is_featured' => (bool) $this->is_featured,

            // Stats
            'views_count' => (int) ($this->views_count ?? 0),
            'replies_count' => (int) ($this->reply_count ?? 0),
            'likes_count' => (int) ($this->likes_count ?? 0),

            // Author
            'author' => $this->when($this->relationLoaded('user') && $this->user, function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'avatar' => $this->user->avatar ? url('storage/' . $this->user->avatar) : null,
                ];
            }),

            // Category
            'category' => $this->when($this->relationLoaded('category') && $this->category, function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),

            // Replies (conditional)
            'replies' => ForumReplyResource::collection($this->whenLoaded('replies')),

            // Timestamps
            'last_activity_at' => $this->last_activity_at
                ? (is_string($this->last_activity_at) ? $this->last_activity_at : $this->last_activity_at->toIso8601String())
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => url("/api/admin/forums/{$this->id}"),
                'replies' => url("/api/admin/forums/{$this->id}/replies"),
            ],
        ];
    }
}
