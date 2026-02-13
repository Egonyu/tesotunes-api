<?php

namespace App\Models\Traits;

trait Featurable
{
    /**
     * Scope a query to only include featured items.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Check if the model is featured.
     */
    public function isFeatured(): bool
    {
        return (bool) $this->is_featured;
    }

    /**
     * Mark the model as featured.
     */
    public function markAsFeatured(): bool
    {
        return $this->update(['is_featured' => true]);
    }

    /**
     * Unmark the model as featured.
     */
    public function unmarkAsFeatured(): bool
    {
        return $this->update(['is_featured' => false]);
    }
}
