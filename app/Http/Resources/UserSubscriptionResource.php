<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        if (! $this->resource || ! $this->resource->isActive()) {
            return [
                'has_subscription' => false,
                'plan' => 'free',
                'tier' => 'free',
                'ad_free' => false,
                'offline_access' => false,
            ];
        }

        $plan = $this->resource->subscriptionPlan;

        return [
            'has_subscription' => true,
            'id' => $this->resource->id,
            'plan' => $plan?->slug ?? 'unknown',
            'plan_name' => $plan?->name,
            'tier' => $plan?->tier,
            'status' => $this->resource->status,
            'started_at' => $this->resource->started_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'days_remaining' => $this->resource->daysUntilExpiry(),
            'auto_renew' => (bool) $this->resource->auto_renew,
            'ad_free' => (bool) ($plan?->ad_free ?? false),
            'offline_access' => (bool) ($plan?->allows_offline ?? false),
            'limits' => [
                'downloads_per_day' => $plan?->max_downloads_per_day ?? $plan?->downloads_per_day ?? 3,
                'audio_quality_kbps' => $plan?->max_audio_quality_kbps ?? 128,
                'uploads_per_month' => $plan?->max_uploads_per_month ?? 0,
            ],
        ];
    }
}
