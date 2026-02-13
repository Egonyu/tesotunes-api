<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Song;
use App\Models\AuditLog;

class SongPolicy
{
    /**
     * Determine if the user can view any songs.
     */
    public function viewAny(?User $user): bool
    {
        // Anyone can view published songs (even guests)
        return true;
    }

    /**
     * Determine if the user can view the song.
     */
    public function view(?User $user, Song $song): bool
    {
        // Published songs are viewable by everyone
        if ($song->status === 'published') {
            return true;
        }

        // For non-published songs, must be logged in
        if (!$user) {
            return false;
        }

        // Owner can view their own songs in any status
        if ($user->artist && $user->artist->id === $song->artist_id) {
            return true;
        }

        // Admins and moderators can view any song
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine if the user can create songs.
     */
    public function create(User $user): bool
    {
        // Must have upload permission
        if (!$user->hasPermission('music.upload')) {
            return false;
        }

        // Must be verified artist
        if (!$user->isVerified() || !$user->hasRole('artist')) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can update the song.
     */
    public function update(User $user, Song $song): bool
    {
        // Must have edit permission
        if (!$user->hasPermission('music.edit')) {
            AuditLog::logActivity($user->id, 'unauthorized_song_edit_attempt', [
                'song_id' => $song->id,
                'song_title' => $song->title,
            ]);
            return false;
        }

        // Super admin can edit anything
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin can edit anything
        if ($user->hasRole('admin')) {
            return true;
        }

        // Moderator can edit anything
        if ($user->hasRole('moderator')) {
            return true;
        }

        // Artists can only edit their own songs
        return $user->artist && $user->artist->id === $song->artist_id;
    }

    /**
     * Determine if the user can delete the song.
     */
    public function delete(User $user, Song $song): bool
    {
        // Must have delete permission
        if (!$user->hasPermission('music.delete')) {
            AuditLog::logActivity($user->id, 'unauthorized_song_delete_attempt', [
                'song_id' => $song->id,
                'song_title' => $song->title,
            ]);
            return false;
        }

        // Super admin can delete anything
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin can delete anything
        if ($user->hasRole('admin')) {
            return true;
        }

        // Artists can only delete their own songs
        return $user->artist && $user->artist->id === $song->artist_id;
    }

    /**
     * Determine if the user can moderate the song.
     */
    public function moderate(User $user, Song $song): bool
    {
        // Need music.moderate permission (moderators and admins)
        return $user->hasPermission('music.moderate');
    }

    /**
     * Determine if the user can approve the song.
     */
    public function approve(User $user, Song $song): bool
    {
        // Need music.approve permission
        if (!$user->hasPermission('music.approve')) {
            return false;
        }

        // Can't approve own songs
        if ($user->artist && $user->artist->id === $song->artist_id) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine if the user can like the song.
     */
    public function like(User $user, Song $song): bool
    {
        // Only active users can like published songs
        return $user->is_active && $song->status === 'published';
    }

    /**
     * Determine if the user can play the song.
     */
    public function play(?User $user, Song $song): bool
    {
        // Published songs can be played by anyone
        if ($song->status === 'published') {
            return true;
        }

        // For non-published songs, must be logged in
        if (!$user) {
            return false;
        }

        // Check if user owns the song
        return $user->artist && $user->artist->id === $song->artist_id;
    }

    /**
     * Determine if the user can download the song.
     */
    public function download(User $user, Song $song): bool
    {
        // Must be able to play the song first
        if (!$this->play($user, $song)) {
            return false;
        }

        // Check user's download limits (freemium model)
        if (!$user->canDownload()) {
            return false;
        }

        // Free songs can be downloaded if artist allows
        if ($song->is_free && $song->allow_downloads) {
            return true;
        }

        // Paid songs require purchase - placeholder
        // Implement based on your payment system
        return false;
    }

    /**
     * Determine if the user can restore the song.
     */
    public function restore(User $user, Song $song): bool
    {
        // Only admins can restore deleted songs
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * Determine if the user can permanently delete the song.
     */
    public function forceDelete(User $user, Song $song): bool
    {
        // Only super admin can permanently delete
        return $user->hasRole('super_admin');
    }
}
