<?php

namespace App\Modules\Podcast\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodcastListen extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'episode_id',
        'podcast_id',
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'country',
        'city',
        'listen_duration',
        'episode_duration',
        'device_type',
        'platform',
        'started_at',
        'last_position',
        'listened_at',
    ];

    protected $casts = [
        'episode_id' => 'integer',
        'podcast_id' => 'integer',
        'user_id' => 'integer',
        'listen_duration' => 'integer',
        'episode_duration' => 'integer',
        'last_position' => 'integer',
        'started_at' => 'datetime',
        'listened_at' => 'datetime',
    ];

    // Relationships
    public function episode(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\PodcastEpisode::class, 'episode_id');
    }

    public function podcast(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\Podcast::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getCompletionPercentageAttribute(): float
    {
        if ($this->episode_duration === 0) {
            return 0;
        }

        return round(($this->listen_duration / $this->episode_duration) * 100, 2);
    }

    // Scopes
    public function scopeCompleted($query, $threshold = 90)
    {
        return $query->whereRaw("(listen_duration / episode_duration * 100) >= ?", [$threshold]);
    }

    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country', $countryCode);
    }

    public function scopeByDevice($query, string $deviceType)
    {
        return $query->where('device_type', $deviceType);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('listened_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('listened_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('listened_at', now()->year)
                     ->whereMonth('listened_at', now()->month);
    }

    // Helper Methods
    public function isCompleted(int $threshold = 90): bool
    {
        return $this->completion_percentage >= $threshold;
    }

    public function updatePosition(int $position): void
    {
        $this->update(['last_position' => $position]);
    }
}
