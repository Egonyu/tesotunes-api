<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PodcastCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'is_active',
        'sort_order',
        'display_order',
        'podcast_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Hierarchical relationships
     */
    public function parent()
    {
        return $this->belongsTo(PodcastCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(PodcastCategory::class, 'parent_id');
    }

    /**
     * Get the podcasts in this category
     */
    public function podcasts()
    {
        return $this->hasMany(Podcast::class, 'podcast_category_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->orderBy('sort_order')
                     ->orderBy('name');
    }

    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeWithChildren($query)
    {
        return $query->with('children');
    }

    /**
     * Increment the podcast count
     */
    public function incrementPodcastCount(): void
    {
        $this->increment('podcast_count');
    }

    /**
     * Decrement the podcast count
     */
    public function decrementPodcastCount(): void
    {
        $this->decrement('podcast_count');
    }
}
