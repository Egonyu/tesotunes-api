<?php

namespace App\Traits;

trait HasReviews
{
    /**
     * Get all reviews for this model.
     */
    public function reviews()
    {
        return $this->morphMany(\App\Modules\Store\Models\Review::class, 'reviewable');
    }

    /**
     * Get average rating.
     */
    public function averageRating()
    {
        return $this->reviews()->avg('rating') ?? 0;
    }

    /**
     * Get total reviews count.
     */
    public function reviewsCount()
    {
        return $this->reviews()->count();
    }
}
