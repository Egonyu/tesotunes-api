<?php

namespace App\Modules\Promotions\Policies;

use App\Models\User;
use App\Modules\Promotions\Models\PromotionRequest;

class PromotionRequestPolicy
{
    public function view(?User $user, PromotionRequest $promotionRequest): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PromotionRequest $promotionRequest): bool
    {
        return $user->id === $promotionRequest->created_by_user_id
            && in_array($promotionRequest->status, [
                PromotionRequest::STATUS_DRAFT,
                PromotionRequest::STATUS_OPEN,
            ]);
    }

    public function delete(User $user, PromotionRequest $promotionRequest): bool
    {
        return $user->id === $promotionRequest->created_by_user_id
            && in_array($promotionRequest->status, [
                PromotionRequest::STATUS_DRAFT,
                PromotionRequest::STATUS_OPEN,
            ]);
    }

    public function manageApplications(User $user, PromotionRequest $promotionRequest): bool
    {
        return $user->id === $promotionRequest->created_by_user_id;
    }

    public function apply(User $user, PromotionRequest $promotionRequest): bool
    {
        return $user->id !== $promotionRequest->created_by_user_id
            && in_array($promotionRequest->status, [
                PromotionRequest::STATUS_OPEN,
                PromotionRequest::STATUS_REVIEWING,
            ]);
    }
}
