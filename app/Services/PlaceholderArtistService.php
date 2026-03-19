<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlaceholderArtistService
{
    public function findOrCreate(string $artistName, User $catalogManager, array $attributes = []): Artist
    {
        $normalizedName = mb_strtolower(trim($artistName));

        $artist = Artist::query()
            ->where('is_placeholder', true)
            ->whereRaw('LOWER(COALESCE(stage_name, name)) = ?', [$normalizedName])
            ->first();

        if ($artist) {
            if (! $artist->catalog_manager_user_id) {
                $artist->forceFill([
                    'catalog_manager_user_id' => $catalogManager->id,
                ])->save();
            }

            return $artist;
        }

        $user = $this->createPlaceholderUser($artistName, $catalogManager);
        $slug = $this->generateUniqueArtistSlug($artistName);

        return Artist::create([
            'user_id' => $user->id,
            'name' => $artistName,
            'stage_name' => $artistName,
            'slug' => $slug,
            'status' => 'active',
            'can_upload' => false,
            'auto_publish' => false,
            'require_approval' => true,
            'is_verified' => false,
            'is_placeholder' => true,
            'claim_status' => 'unclaimed',
            'claimed_user_id' => null,
            'catalog_manager_user_id' => $catalogManager->id,
            'bio' => $attributes['bio'] ?? null,
            'country' => $attributes['country'] ?? 'Uganda',
            'city' => $attributes['city'] ?? null,
            'primary_genre_id' => $attributes['primary_genre_id'] ?? null,
        ]);
    }

    private function createPlaceholderUser(string $artistName, User $catalogManager): User
    {
        $baseSlug = Str::slug($artistName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'placeholder-artist';
        $suffix = Str::lower(Str::random(8));
        $username = Str::limit($baseSlug, 20, '').'-'.$suffix;
        $email = $baseSlug.'-'.$suffix.'@placeholder.tesotunes.local';

        return User::create([
            'name' => $artistName,
            'display_name' => $artistName,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'status' => 'active',
            'is_active' => true,
            'is_artist' => false,
            'entity_type' => 'placeholder_artist',
            'created_by' => $catalogManager->id,
            'email_verified_at' => now(),
            'country' => 'Uganda',
            'timezone' => 'Africa/Kampala',
        ]);
    }

    private function generateUniqueArtistSlug(string $artistName): string
    {
        $baseSlug = Str::slug($artistName);
        $slug = $baseSlug !== '' ? $baseSlug : 'placeholder-artist';
        $originalSlug = $slug;
        $counter = 1;

        while (Artist::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        return $slug;
    }
}
