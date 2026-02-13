<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id ?? $this->uuid,
            'type'       => $this->type,
            'category'   => $this->category ?? $this->when(isset($this->data['module']), $this->data['module'] ?? null),
            'title'      => $this->title ?? $this->data['title'] ?? null,
            'message'    => $this->message ?? $this->data['message'] ?? null,
            'icon'       => $this->icon ?? 'bell',
            'image'      => $this->image ?? null,
            'action_url' => $this->action_url ?? $this->data['action_url'] ?? null,
            'action_text'=> $this->action_text ?? null,
            'priority'   => $this->priority ?? 'normal',
            'is_read'    => (bool) ($this->is_read ?? ($this->read_at !== null)),
            'read_at'    => $this->read_at?->toIso8601String(),
            'data'       => $this->data ?? [],
            'actor'      => $this->when($this->relationLoaded('actor') && $this->actor, [
                'id'   => $this->actor?->id,
                'name' => $this->actor?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
