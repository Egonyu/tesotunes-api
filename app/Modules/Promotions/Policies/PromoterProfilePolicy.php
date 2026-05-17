<?php

namespace App\Modules\Promotions\Policies;

use App\Models\User;
use App\Modules\Promotions\Models\PromoterProfile;

class PromoterProfilePolicy
{
    public function view(?User $user, PromoterProfile $profile): bool
    {
        return $profile->status === PromoterProfile::STATUS_ACTIVE;
    }

    public function update(User $user, PromoterProfile $profile): bool
    {
        return $user->id === $profile->user_id;
    }

    public function delete(User $user, PromoterProfile $profile): bool
    {
        return $user->id === $profile->user_id
            || in_array($user->role, ['admin', 'super_admin']);
    }

    public function verify(User $user, PromoterProfile $profile): bool
    {
        return in_array($user->role, ['admin', 'super_admin']);
    }
}
