<?php

namespace App\Modules\Promotions\Services;

use App\Models\User;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Store\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoterOnboardingService
{
    /**
     * Onboard a user as a promoter.
     *
     * Idempotent: safe to call multiple times — returns existing profile if one exists.
     * Any role can become a promoter (no artist role required).
     *
     * @param  array<string, mixed>  $data
     */
    public function onboard(User $user, array $data): PromoterProfile
    {
        return DB::transaction(function () use ($user, $data): PromoterProfile {
            $existing = PromoterProfile::where('user_id', $user->id)->first();

            if ($existing) {
                return $existing;
            }

            $store = $this->ensureStore($user);

            $displayName = $data['display_name'] ?? $user->display_name ?? $user->name ?? $user->username ?? 'Promoter';
            $baseSlug = Str::slug($data['slug'] ?? $user->username ?? $displayName);
            $slug = $baseSlug;
            $suffix = 1;

            while (PromoterProfile::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            return PromoterProfile::create([
                'user_id' => $user->id,
                'store_id' => $store?->id,
                'display_name' => $displayName,
                'slug' => $slug,
                'bio' => $data['bio'] ?? null,
                'platforms' => $data['platforms'] ?? null,
                'niches' => $data['niches'] ?? null,
                'audience_regions' => $data['audience_regions'] ?? null,
                'audience_summary' => $data['audience_summary'] ?? null,
                'social_links' => $data['social_links'] ?? null,
                'response_time_hours' => isset($data['response_time_hours']) ? (int) $data['response_time_hours'] : null,
                'status' => PromoterProfile::STATUS_ACTIVE,
                'onboarded_at' => now(),
            ]);
        });
    }

    /**
     * Update an existing promoter profile (user-editable fields only).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(PromoterProfile $profile, array $data): PromoterProfile
    {
        $allowed = [
            'display_name', 'bio', 'platforms', 'niches', 'audience_regions',
            'audience_summary', 'social_links', 'portfolio_items', 'proof_points',
            'campaign_highlights', 'response_time_hours',
        ];

        $profile->update(array_intersect_key($data, array_flip($allowed)));

        return $profile->fresh();
    }

    /**
     * Ensure the user has a store; auto-provision one if not.
     *
     * Artists already have stores created at artist onboarding.
     * Non-artist users get a generic 'promoter' type store here.
     */
    public function ensureStore(User $user): ?Store
    {
        if (! config('promotions.auto_provision_store', true)) {
            return null;
        }

        $existing = Store::where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        $name = ($user->display_name ?? $user->name ?? $user->username ?? 'Promoter').' Store';
        $baseSlug = Str::slug($user->username ?? $name);
        $slug = $baseSlug;
        $suffix = 1;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return Store::create([
            'user_id' => $user->id,
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'name' => $name,
            'slug' => $slug,
            'store_type' => config('promotions.default_store_type', 'promoter'),
            'subscription_tier' => config('promotions.default_store_tier', 'free'),
            'status' => Store::STATUS_ACTIVE,
            'description' => 'Promoter services store',
        ]);
    }
}
