<?php

namespace App\Policies;

use App\Models\Podcast;
use App\Models\User;

class PodcastPolicy
{
    /**
     * Determine if the user can view the podcast.
     */
    public function view(?User $user, Podcast $podcast): bool
    {
        // Published podcasts are viewable by anyone
        if ($podcast->status === 'published') {
            return true;
        }

        // Draft/archived podcasts only viewable by owner
        return $user && $podcast->isOwnedBy($user);
    }

    /**
     * Determine if the user can create podcasts.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create podcasts
    }

    /**
     * Determine if the user can update the podcast.
     */
    public function update(User $user, Podcast $podcast): bool
    {
        return $podcast->isOwnedBy($user);
    }

    /**
     * Determine if the user can delete the podcast.
     */
    public function delete(User $user, Podcast $podcast): bool
    {
        return $podcast->isOwnedBy($user);
    }

    /**
     * Determine if the user can publish the podcast.
     */
    public function publish(User $user, Podcast $podcast): bool
    {
        return $podcast->isOwnedBy($user);
    }

    /**
     * Determine if the user can manage episodes.
     */
    public function manageEpisodes(User $user, Podcast $podcast): bool
    {
        return $podcast->isOwnedBy($user) || $podcast->hasCollaborator($user);
    }
}
