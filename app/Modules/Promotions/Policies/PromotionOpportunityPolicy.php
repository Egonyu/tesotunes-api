<?php

namespace App\Modules\Promotions\Policies;

use App\Models\User;
use App\Modules\Promotions\Models\PromotionOpportunity;

class PromotionOpportunityPolicy
{
    public function view(?User $user, PromotionOpportunity $opportunity): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PromotionOpportunity $opportunity): bool
    {
        return $user->id === $opportunity->created_by_user_id
            && in_array($opportunity->status, [
                PromotionOpportunity::STATUS_DRAFT,
                PromotionOpportunity::STATUS_OPEN,
            ]);
    }

    public function delete(User $user, PromotionOpportunity $opportunity): bool
    {
        return $user->id === $opportunity->created_by_user_id
            && in_array($opportunity->status, [
                PromotionOpportunity::STATUS_DRAFT,
                PromotionOpportunity::STATUS_OPEN,
            ]);
    }

    public function manageApplications(User $user, PromotionOpportunity $opportunity): bool
    {
        return $user->id === $opportunity->created_by_user_id;
    }

    public function apply(User $user, PromotionOpportunity $opportunity): bool
    {
        return $user->id !== $opportunity->created_by_user_id
            && in_array($opportunity->status, [
                PromotionOpportunity::STATUS_OPEN,
                PromotionOpportunity::STATUS_REVIEWING,
            ]);
    }
}
