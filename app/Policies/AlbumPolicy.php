<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;
use App\Models\AuditLog;

class AlbumPolicy
{
    /**
     * Determine whether the user can view any albums.
     */
    public function viewAny(?User $user): bool
    {
        // Anyone can view published albums
        return true;
    }

    /**
     * Determine whether the user can view the album.
     */
    public function view(?User $user, Album $album): bool
    {
        // Published albums are viewable by everyone
        if ($album->status === 'published') {
            return true;
        }

        // For non-published albums, must be logged in
        if (!$user) {
            return false;
        }

        // Owner can view their own albums
        if ($user->artist && $user->artist->id === $album->artist_id) {
            return true;
        }

        // Admins and moderators can view any album
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can create albums.
     */
    public function create(User $user): bool
    {
        // Must have album.create permission
        if (!$user->hasPermission('album.create')) {
            return false;
        }

        // Must be verified artist
        if (!$user->isVerified() || !$user->hasRole('artist')) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the album.
     */
    public function update(User $user, Album $album): bool
    {
        // Must have edit permission
        if (!$user->hasPermission('album.edit')) {
            AuditLog::logActivity($user->id, 'unauthorized_album_edit_attempt', [
                'album_id' => $album->id,
                'album_title' => $album->title,
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

        // Artists can only edit their own albums
        return $user->artist && $user->artist->id === $album->artist_id;
    }

    /**
     * Determine whether the user can delete the album.
     */
    public function delete(User $user, Album $album): bool
    {
        // Must have delete permission
        if (!$user->hasPermission('album.delete')) {
            AuditLog::logActivity($user->id, 'unauthorized_album_delete_attempt', [
                'album_id' => $album->id,
                'album_title' => $album->title,
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

        // Artists can only delete their own albums
        return $user->artist && $user->artist->id === $album->artist_id;
    }

    /**
     * Determine whether the user can restore the album.
     */
    public function restore(User $user, Album $album): bool
    {
        // Only admins can restore deleted albums
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can permanently delete the album.
     */
    public function forceDelete(User $user, Album $album): bool
    {
        // Only super admin can permanently delete
        return $user->hasRole('super_admin');
    }
}
