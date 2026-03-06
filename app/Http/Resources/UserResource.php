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
            'is_artist' => (bool) $this->is_artist,
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
            'subscription' => $this->when($this->relationLoaded('subscription'), function () {
                $sub = $this->subscription;
                if (! $sub || ! $sub->isActive()) {
                    return [
                        'has_subscription' => false,
                        'plan' => 'free',
                        'tier' => 'free',
                    ];
                }

                $plan = $sub->subscriptionPlan;

                return [
                    'has_subscription' => true,
                    'id' => $sub->id,
                    'plan' => $plan?->slug ?? 'unknown',
                    'plan_name' => $plan?->name,
                    'tier' => $plan?->tier,
                    'status' => $sub->status,
                    'started_at' => $sub->started_at?->toIso8601String(),
                    'expires_at' => $sub->expires_at?->toIso8601String(),
                    'days_remaining' => $sub->daysUntilExpiry(),
                    'auto_renew' => (bool) $sub->auto_renew,
                    'limits' => [
                        'downloads_per_day' => $plan?->max_downloads_per_day ?? $plan?->downloads_per_day ?? 3,
                        'audio_quality_kbps' => $plan?->max_audio_quality_kbps ?? 128,
                        'uploads_per_month' => $plan?->max_uploads_per_month ?? 0,
                    ],
                ];
            }),
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
