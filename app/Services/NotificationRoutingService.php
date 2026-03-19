<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRoutingService
{
    public function moderationRecipients(): Collection
    {
        return $this->resolveRecipients(
            ['admin', 'super_admin', 'moderator'],
            ['music.moderate', 'admin.music']
        );
    }

    public function artistApplicationReviewers(): Collection
    {
        return $this->resolveRecipients(
            ['admin', 'super_admin', 'moderator'],
            ['user.moderate', 'admin.users']
        );
    }

    public function claimReviewRecipients(): Collection
    {
        return $this->resolveRecipients(
            ['admin', 'super_admin'],
            ['catalog.claim.review']
        );
    }

    private function resolveRecipients(array $roles, array $permissions = []): Collection
    {
        return User::query()
            ->with(['roles.permissions'])
            ->get()
            ->filter(function (User $user) use ($roles, $permissions): bool {
                if (($user->status ?? null) === 'suspended') {
                    return false;
                }

                if ($user->role && in_array($user->role, $roles, true)) {
                    return true;
                }

                if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($roles)) {
                    return true;
                }

                foreach ($permissions as $permission) {
                    if (method_exists($user, 'hasPermission') && $user->hasPermission($permission)) {
                        return true;
                    }
                }

                return false;
            })
            ->unique('id')
            ->values();
    }
}
