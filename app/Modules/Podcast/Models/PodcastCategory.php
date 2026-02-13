<?php

namespace App\Modules\Podcast\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PodcastCategory extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\Modules\Podcast\Models\PodcastCategoryFactory::new();
    }

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'is_active',
        'sort_order',
        'display_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the parent category
     */
    public function parent()
    {
        return $this->belongsTo(PodcastCategory::class, 'parent_id');
    }

    /**
     * Get the child categories
     */
    public function children()
    {
        return $this->hasMany(PodcastCategory::class, 'parent_id');
    }

    /**
     * Get all podcasts in this category
     */
    public function podcasts()
    {
        return $this->hasMany(Podcast::class, 'podcast_category_id');
    }

    /**
     * Scope: Get only active categories
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->orderBy('sort_order')
                     ->orderBy('name');
    }

    /**
     * Scope: Get root categories (no parent)
     */
    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: Get categories with their children
     */
    public function scopeWithChildren($query)
    {
        return $query->with('children');
    }

    /**
     * Check if this category is a parent (has children)
     */
    public function isParent(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Check if this category is a child (has a parent)
     */
    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }
}
