<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AwardCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
        'icon',
        'artwork',
        'nominee_type',
        'category_type',
        'max_nominees',
        'max_nominations_per_user',
        'max_votes_per_user',
        'is_jury_category',
        'jury_weight_percentage',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_jury_category' => 'boolean',
        'sort_order' => 'integer',
        'max_nominees' => 'integer',
        'max_nominations_per_user' => 'integer',
        'max_votes_per_user' => 'integer',
        'jury_weight_percentage' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->uuid)) {
                $category->uuid = (string) Str::uuid();
            }
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
            if (empty($category->category_type)) {
                $category->category_type = $category->nominee_type ?? 'general';
            }
        });
    }

    // Relationships
    public function nominations(): HasMany
    {
        return $this->hasMany(AwardNomination::class, 'category_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('category_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', '%'.escape_like($term).'%');
    }
}
