<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PledgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'amount' => (float) $this->amount,
            'message' => $this->message,
            'is_anonymous' => (bool) $this->is_anonymous,

            // Pledger (hidden for anonymous pledges)
            'user' => $this->when(!$this->is_anonymous && $this->relationLoaded('user') && $this->user, fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar ? url('storage/' . $this->user->avatar) : null,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
