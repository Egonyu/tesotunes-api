<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Genre extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'is_active',
        'sort_order',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'song_genres'); // Corrected: plural form matches migration
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Accessors
    public function getSongCountAttribute(): int
    {
        return $this->songs()->published()->count();
    }

    public function getPopularSongsAttribute()
    {
        return $this->songs()
            ->published()
            ->orderBy('play_count', 'desc')
            ->limit(10)
            ->get();
    }

    // Helper methods for African market - local genre discovery
    public function getLocalArtistsAttribute()
    {
        return $this->songs()
            ->published()
            ->with('artist')
            ->get()
            ->pluck('artist')
            ->unique('id')
            ->take(20);
    }

    // Media Library Configuration
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(100)
            ->height(100)
            ->sharpen(10)
            ->optimize();

        $this->addMediaConversion('sm')
            ->width(200)
            ->height(200)
            ->sharpen(10)
            ->optimize();

        $this->addMediaConversion('md')
            ->width(400)
            ->height(400)
            ->sharpen(10)
            ->optimize();

        $this->addMediaConversion('lg')
            ->width(800)
            ->height(800)
            ->sharpen(10)
            ->optimize();
    }

    public function getArtworkUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('artwork', 'md');
    }
}