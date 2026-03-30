<?php

namespace App\Traits;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasReviews
{
    /**
     * Get all reviews for this model.
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * Only approved reviews.
     */
    public function approvedReviews(): MorphMany
    {
        return $this->reviews()->where('status', Review::STATUS_APPROVED);
    }

    /**
     * Get average rating.
     */
    public function averageRating(): float
    {
        return (float) ($this->approvedReviews()->avg('rating') ?? 0);
    }

    /**
     * Get total reviews count.
     */
    public function reviewsCount(): int
    {
        return (int) $this->approvedReviews()->count();
    }

    /**
     * Check if a user has reviewed this model.
     */
    public function hasReviewedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->reviews()->where('user_id', $user->id)->exists();
    }

    /**
     * Add a review to this model.
     */
    public function addReview(User $user, int $rating, string $content, array $attributes = []): Review
    {
        return Review::create(array_merge([
            'user_id' => $user->id,
            'reviewable_type' => static::class,
            'reviewable_id' => $this->getKey(),
            'rating' => $rating,
            'content' => $content,
            'status' => Review::STATUS_APPROVED,
        ], $attributes));
    }
}
