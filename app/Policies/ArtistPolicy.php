<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;

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

        // Only active users can follow approved artists. Status is a string column;
        // VISIBLE_STATUSES accepts canonical 'approved' plus legacy 'active'
        // during the KYC canonicalization grace window.
        return $user->is_active && in_array($artist->status, Artist::VISIBLE_STATUSES, true);
    }

    /**
     * Determine if the user can unfollow the artist
     */
    public function unfollow(User $user, Artist $artist): bool
    {
        return $this->follow($user, $artist);
    }
}
