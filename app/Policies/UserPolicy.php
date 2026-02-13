<?php

namespace App\Policies;

use App\Models\User;
use App\Models\AuditLog;

class UserPolicy
{
    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        // Need user.view permission (admins and moderators)
        return $user->hasPermission('user.view');
    }

    /**
     * Determine whether the user can view the user profile.
     */
    public function view(User $user, User $model): bool
    {
        // Users can view their own profile
        if ($user->id === $model->id) {
            return true;
        }

        // Admins and moderators can view any profile
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        // Need user.create permission (admin only)
        return $user->hasPermission('user.create');
    }

    /**
     * Determine whether the user can update the user.
     */
    public function update(User $user, User $model): bool
    {
        // Users can update their own profile
        if ($user->id === $model->id) {
            return true;
        }

        // Must have user.edit permission
        if (!$user->hasPermission('user.edit')) {
            AuditLog::logActivity($user->id, 'unauthorized_user_edit_attempt', [
                'target_user_id' => $model->id,
                'target_user_email' => $model->email,
            ]);
            return false;
        }

        // Super admin can edit anyone
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin can edit non-admins
        if ($user->hasRole('admin') && !$model->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        // Moderators can edit regular users
        if ($user->hasRole('moderator') && $model->hasRole('user')) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the user.
     */
    public function delete(User $user, User $model): bool
    {
        // Can't delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        // Must have user.delete permission
        if (!$user->hasPermission('user.delete')) {
            AuditLog::logActivity($user->id, 'unauthorized_user_delete_attempt', [
                'target_user_id' => $model->id,
                'target_user_email' => $model->email,
            ]);
            return false;
        }

        // Super admin can delete anyone except other super admins
        if ($user->hasRole('super_admin')) {
            return !$model->hasRole('super_admin') || $user->id !== $model->id;
        }

        // Admin can delete non-admins
        if ($user->hasRole('admin') && !$model->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can ban the user.
     */
    public function ban(User $user, User $model): bool
    {
        // Can't ban yourself
        if ($user->id === $model->id) {
            return false;
        }

        // Must have user.moderate or user.ban permission
        if (!$user->hasPermission('user.moderate') && !$user->hasPermission('user.ban')) {
            return false;
        }

        // Can't ban higher level users
        if ($model->hasRole('super_admin')) {
            return false;
        }

        if ($model->hasRole('admin') && !$user->hasRole('super_admin')) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can restore the user.
     */
    public function restore(User $user, User $model): bool
    {
        // Only admins can restore deleted users
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can permanently delete the user.
     */
    public function forceDelete(User $user, User $model): bool
    {
        // Only super admin can permanently delete
        return $user->hasRole('super_admin') && $user->id !== $model->id;
    }

    /**
     * Determine whether the user can assign roles to the user.
     */
    public function assignRoles(User $user, User $model): bool
    {
        // Need user.manage_roles permission
        if (!$user->hasPermission('user.manage_roles')) {
            return false;
        }

        // Can't change your own roles
        if ($user->id === $model->id) {
            return false;
        }

        // Super admin can assign any role
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admins can assign roles to non-admins
        if ($user->hasRole('admin') && !$model->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return false;
    }
    
    /**
     * Determine whether the user can manage modules.
     */
    public function manageModules(User $user): bool
    {
        // Only super admins and admins can manage modules
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
