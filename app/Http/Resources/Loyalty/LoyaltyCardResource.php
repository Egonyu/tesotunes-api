<?php

namespace App\Http\Resources\Loyalty;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'logo_url'    => StorageHelper::url($this->logo_url),
            'banner_url'  => StorageHelper::url($this->banner_url),
            'status'      => $this->status,

            // Branding
            'primary_color'   => $this->primary_color,
            'secondary_color' => $this->secondary_color,

            // Tiers
            'tiers' => $this->tiers,

            // Subscription options
            'allow_monthly' => (bool) $this->allow_monthly,
            'allow_yearly'  => (bool) $this->allow_yearly,
            'auto_renew'    => (bool) $this->auto_renew,

            // Stats
            'total_members' => (int) ($this->total_members ?? 0),

            // Artist
            'artist' => $this->when($this->relationLoaded('artist') && $this->artist, function () {
                return [
                    'id'     => $this->artist->id,
                    'name'   => $this->artist->name ?? $this->artist->artist_name,
                    'avatar' => StorageHelper::avatarUrl(
                        $this->artist->profile_image ?? null,
                        $this->artist->name ?? $this->artist->artist_name,
                    ),
                ];
            }),

            // Rewards summary (count only for listings)
            'rewards_count' => $this->when(
                $this->relationLoaded('rewards'),
                fn () => $this->rewards->count(),
            ),

            // Full rewards (for detail views)
            'rewards' => $this->when(
                $this->relationLoaded('rewards') && $request->routeIs('*.show'),
                fn () => LoyaltyRewardResource::collection($this->rewards),
            ),

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),

            // Links
            'links' => [
                'self'    => url("/api/loyalty-cards/{$this->slug}"),
                'rewards' => url("/api/loyalty-cards/{$this->slug}/rewards"),
            ],
        ];
    }
}
