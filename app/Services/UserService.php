<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\User;

class UserService
{
    public function activate(User $user): void
    {
        $user->update(['is_active' => true]);
    }

    public function deactivate(User $user): void
    {
        $user->update(['is_active' => false]);
    }

    public function ban(User $user): void
    {
        $this->deactivate($user);
    }

    public function syncArtistApplicationState(User $user, array $attributes): void
    {
        $legacyUserUpdates = [];
        foreach ([
            'full_name',
            'nin_number',
            'phone',
            'mobile_money_number',
            'mobile_money_provider',
            'application_status',
            'rejection_reason',
        ] as $key) {
            if (array_key_exists($key, $attributes)) {
                $legacyUserUpdates[$key] = $attributes[$key];
            }
        }

        if (! empty($legacyUserUpdates)) {
            $user->update($legacyUserUpdates);
        }

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            array_filter([
                'display_name' => $attributes['stage_name'] ?? null,
                'bio' => $attributes['bio'] ?? null,
                'country' => $attributes['country'] ?? null,
                'city' => $attributes['city'] ?? null,
                'instagram_url' => $attributes['social_links']['instagram'] ?? null,
                'twitter_url' => $attributes['social_links']['twitter'] ?? null,
                'facebook_url' => $attributes['social_links']['facebook'] ?? null,
                'youtube_url' => $attributes['social_links']['youtube'] ?? null,
                'tiktok_url' => $attributes['social_links']['tiktok'] ?? null,
            ], fn ($value) => $value !== null)
        );

        if (! User::hasArtistProfilesTable()) {
            return;
        }

        // Legacy: $attributes['verification_documents'] / $attributes['verification_status']
        // are no longer persisted here. Identity verification flows through KycService
        // and writes to users.kyc_status + kyc_documents.

        $user->artistProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'stage_name' => $attributes['stage_name'] ?? $user->artistProfile?->stage_name,
                'real_name' => $attributes['full_name'] ?? $user->artistProfile?->real_name,
                'nin_number' => $attributes['nin_number'] ?? $user->artistProfile?->nin_number,
                'bio' => $attributes['bio'] ?? $user->artistProfile?->bio,
                'website' => $attributes['website_url'] ?? $user->artistProfile?->website,
                'social_links' => $attributes['social_links'] ?? $user->artistProfile?->social_links,
                'genres' => $attributes['genres'] ?? $user->artistProfile?->genres,
                'career_stage' => $attributes['career_stage'] ?? $user->artistProfile?->career_stage,
                'mobile_money_provider' => $attributes['mobile_money_provider'] ?? $user->artistProfile?->mobile_money_provider,
                'mobile_money_number' => $attributes['mobile_money_number'] ?? $user->artistProfile?->mobile_money_number,
                'bank_name' => $attributes['bank_name'] ?? $user->artistProfile?->bank_name,
                'bank_account' => $attributes['bank_account'] ?? $user->artistProfile?->bank_account,
                'payout_method' => $attributes['artist_profile_payout_method'] ?? $user->artistProfile?->payout_method ?? 'mobile_money',
                'profile_completed' => (bool) ($attributes['profile_completed'] ?? true),
                'is_active' => true,
            ]
        );
    }

    public function syncArtistReviewState(User $user, Artist $artist, array $attributes): void
    {
        $applicationStatus = $attributes['application_status'] ?? null;
        $verifiedAt = $attributes['verified_at'] ?? null;
        $verifiedBy = $attributes['verified_by'] ?? null;
        $rejectionReason = $attributes['rejection_reason'] ?? null;
        $isArtist = (bool) ($attributes['is_artist'] ?? false);

        $user->update(array_filter([
            'application_status' => $applicationStatus,
            'verified_at' => $verifiedAt,
            'verified_by' => $verifiedBy,
            'rejection_reason' => $rejectionReason,
            'is_artist' => $isArtist,
            'phone_verified_at' => $attributes['phone_verified_at'] ?? null,
        ], fn ($value) => $value !== null));

        if (! empty($attributes['email_verified_at']) && ! $user->email_verified_at) {
            $user->forceFill([
                'email_verified_at' => $attributes['email_verified_at'],
            ])->save();
        }

        if (! User::hasArtistProfilesTable()) {
            return;
        }

        $user->artistProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'real_name' => $user->full_name ?? $user->artistProfile?->real_name,
                'nin_number' => $user->nin_number ?? $user->artistProfile?->nin_number,
                'verified_at' => $verifiedAt,
                'bio' => $artist->bio ?? $user->artistProfile?->bio,
                'website' => $artist->website_url ?? $user->artistProfile?->website,
                'social_links' => $artist->social_links ?? $user->artistProfile?->social_links,
                'genres' => $attributes['genres'] ?? $user->artistProfile?->genres,
                'mobile_money_provider' => $user->mobile_money_provider ?? $user->artistProfile?->mobile_money_provider,
                'mobile_money_number' => $user->mobile_money_number ?? $user->artistProfile?->mobile_money_number,
                'bank_name' => $user->artistProfile?->bank_name,
                'bank_account' => $user->artistProfile?->bank_account,
                'payout_method' => $user->artistProfile?->payout_method ?? 'mobile_money',
                'profile_completed' => true,
                'is_active' => $attributes['artist_profile_active'] ?? true,
            ]
        );
    }
}
