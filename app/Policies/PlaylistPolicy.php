<?php

namespace App\Policies;

use App\Models\Playlist;
use App\Models\User;
use App\Models\AuditLog;

class PlaylistPolicy
{
    /**
     * Determine whether the user can view any playlists.
     */
    public function viewAny(?User $user): bool
    {
        // Anyone can view public playlists
        return true;
    }

    /**
     * Determine whether the user can view the playlist.
     */
    public function view(?User $user, Playlist $playlist): bool
    {
        // Public playlists are viewable by everyone
        if ($playlist->privacy === 'public') {
            return true;
        }

        // For non-public playlists, must be logged in
        if (!$user) {
            return false;
        }

        // Owner can view their own playlists
        if ($user->id === $playlist->user_id) {
            return true;
        }

        // Collaborators can view
        if ($playlist->collaborators()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Admins can view any playlist
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can create playlists.
     */
    public function create(User $user): bool
    {
        // Must have playlist.create permission
        if (!$user->hasPermission('playlist.create')) {
            return false;
        }

        // Must be active user
        return $user->is_active;
    }

    /**
     * Determine whether the user can update the playlist.
     */
    public function update(User $user, Playlist $playlist): bool
    {
        // Must have edit permission
        if (!$user->hasPermission('playlist.edit')) {
            AuditLog::logActivity($user->id, 'unauthorized_playlist_edit_attempt', [
                'playlist_id' => $playlist->id,
                'playlist_name' => $playlist->name,
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

        // Owner can edit their own playlist
        if ($user->id === $playlist->user_id) {
            return true;
        }

        // Collaborators with edit permission can edit
        $collaborator = $playlist->collaborators()
            ->where('user_id', $user->id)
            ->where('can_edit', true)
            ->first();

        return $collaborator !== null;
    }

    /**
     * Determine whether the user can delete the playlist.
     */
    public function delete(User $user, Playlist $playlist): bool
    {
        // Must have delete permission
        if (!$user->hasPermission('playlist.delete')) {
            AuditLog::logActivity($user->id, 'unauthorized_playlist_delete_attempt', [
                'playlist_id' => $playlist->id,
                'playlist_name' => $playlist->name,
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

        // Only owner can delete their playlist
        return $user->id === $playlist->user_id;
    }

    /**
     * Determine whether the user can add songs to the playlist.
     */
    public function addSongs(User $user, Playlist $playlist): bool
    {
        // Owner can add songs
        if ($user->id === $playlist->user_id) {
            return true;
        }

        // Collaborators with edit permission can add songs
        $collaborator = $playlist->collaborators()
            ->where('user_id', $user->id)
            ->where('can_edit', true)
            ->first();

        return $collaborator !== null;
    }

    /**
     * Determine whether the user can restore the playlist.
     */
    public function restore(User $user, Playlist $playlist): bool
    {
        // Owner can restore their own deleted playlists
        if ($user->id === $playlist->user_id) {
            return true;
        }

        // Admins can restore any playlist
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can permanently delete the playlist.
     */
    public function forceDelete(User $user, Playlist $playlist): bool
    {
        // Only super admin can permanently delete
        return $user->hasRole('super_admin');
    }
}
