<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,

            // Profile
            'display_name' => $this->display_name,
            'avatar' => StorageHelper::avatarUrl($this->avatar, $this->name),
            'bio' => $this->bio,
            'banner' => StorageHelper::url($this->banner),

            // Location
            'country' => $this->country,
            'city' => $this->city,
            'timezone' => $this->timezone,
            'language' => $this->language,

            // Role & Status
            'role' => $this->role ?? 'user',
            'permissions' => method_exists($this->resource, 'getAllPermissions')
                ? $this->resource->getAllPermissions()
                : [],
            'is_artist' => (bool) $this->is_artist,
            'event_organizer' => method_exists($this->resource, 'getEventOrganizerProfile')
                ? $this->resource->getEventOrganizerProfile()
                : ['enabled' => false],
            'is_active' => (bool) $this->is_active,
            'is_verified' => (bool) $this->is_verified,
            'is_premium' => (bool) $this->is_premium,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),

            // Credits
            'credits' => $this->when($this->credits !== null, (int) ($this->credits ?? 0)),

            // Social links
            'social_links' => [
                'instagram' => $this->instagram_url,
                'twitter' => $this->twitter_url,
                'facebook' => $this->facebook_url,
                'youtube' => $this->youtube_url,
                'tiktok' => $this->tiktok_url,
            ],

            // Preferences
            'theme_preference' => $this->theme_preference,

            // Relationships (conditional)
            'settings' => $this->when($this->relationLoaded('settings'), $this->settings),
            'subscription' => $this->when(
                $this->relationLoaded('subscription'),
                fn () => new UserSubscriptionResource($this->subscription)
            ),
            'artist' => $this->when($this->relationLoaded('artist') && $this->artist, function () {
                return [
                    'id' => $this->artist->id,
                    'stage_name' => $this->artist->stage_name,
                    'slug' => $this->artist->slug,
                    'is_verified' => (bool) $this->artist->is_verified,
                ];
            }),

            // Timestamps
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
