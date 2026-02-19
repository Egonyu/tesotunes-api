<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'content' => $this->content,
            'type' => $this->type,
            'is_pinned' => (bool) $this->is_pinned,

            'author' => $this->when($this->relationLoaded('user') && $this->user, fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => StorageHelper::avatarUrl($this->user->avatar, $this->user->name),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
