<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PodcastEpisode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'podcast_id',
        'sponsor_id',
        'title',
        'slug',
        'description',
        'episode_number',
        'season_number',
        'audio_file',
        'file_size',
        'artwork',
        'duration_seconds',
        'type',
        'is_explicit',
        'is_premium',
        'has_preview',
        'preview_duration_seconds',
        'status',
        'published_date',
        'published_at',
        'listen_count',
        'download_count',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'listen_count' => 'integer',
        'download_count' => 'integer',
        'is_premium' => 'boolean',
        'is_explicit' => 'boolean',
        'has_preview' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        // Handle scheduled_for as an alias for published_at
        static::creating(function ($episode) {
            if (isset($episode->scheduled_for) && !isset($episode->published_at)) {
                $episode->published_at = $episode->scheduled_for;
            }
        });

        // Increment podcast episode count when created
        static::created(function ($episode) {
            if ($episode->podcast) {
                $episode->podcast->increment('total_episodes');
            }
        });

        // Decrement podcast episode count when deleted
        static::deleted(function ($episode) {
            if ($episode->podcast) {
                $episode->podcast->decrement('total_episodes');
            }
        });
    }

    public function setScheduledForAttribute($value)
    {
        $this->attributes['published_at'] = $value;
    }

    public function getScheduledForAttribute()
    {
        return $this->attributes['published_at'] ?? null;
    }

    public function setDurationAttribute($value)
    {
        $this->attributes['duration_seconds'] = $value;
    }

    public function getDurationAttribute()
    {
        return $this->attributes['duration_seconds'] ?? 0;
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }

    /**
     * Relationships
     */
    public function podcast(): BelongsTo
    {
        return $this->belongsTo(Podcast::class);
    }

    /**
     * Scopes
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    /**
     * Helper Methods
     */
    public function isPublished()
    {
        return $this->status === 'published' 
            && $this->published_at 
            && $this->published_at <= now();
    }

    public function publish()
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $this;
    }

    public function incrementListenCount()
    {
        $this->increment('listen_count');
        
        // Also increment podcast's total listen count
        if ($this->podcast) {
            $this->podcast->increment('total_listen_count');
        }
    }

    public function incrementDownloadCount()
    {
        $this->increment('download_count');
    }

    public function shouldPublish()
    {
        if ($this->status !== 'scheduled') {
            return false;
        }

        return $this->published_at && $this->published_at <= now();
    }

    public function getDurationFormattedAttribute()
    {
        if (!$this->duration_seconds) {
            return '0:00';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getFileSizeFormattedAttribute()
    {
        if (!isset($this->attributes['file_size'])) {
            return 'N/A';
        }

        $bytes = $this->attributes['file_size'];
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
