<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Artist;

class ArtistPolicy
{
    /**
     * Determine if the user can follow the artist
     */
    public function follow(User $user, Artist $artist): bool
    {
        // Users can't follow themselves if they are artists
        if ($user->artist && $user->artist->id === $artist->id) {
            return false;
        }

        // Only active users can follow
        return $user->is_active && $artist->status === 'active';
    }

    /**
     * Determine if the user can unfollow the artist
     */
    public function unfollow(User $user, Artist $artist): bool
    {
        return $this->follow($user, $artist);
    }
}