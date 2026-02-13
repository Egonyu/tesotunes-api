<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayHistory extends Model
{
    use HasFactory;

    protected $table = 'play_histories';
    
    // Disable default timestamps since we use played_at
    public $timestamps = false;
    
    // Use played_at as the primary timestamp column
    const CREATED_AT = 'played_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'song_id',
        'artist_id',
        'album_id',
        'played_at',
        'duration_played_seconds',
        'completed',
        'skipped',
        'completion_percentage',
        'ip_address',
        'device_type',
        'quality',
        'country',
        'city',
    ];

    protected $casts = [
        'played_at' => 'datetime',
        'duration_played_seconds' => 'integer',
        'completed' => 'boolean',
        'skipped' => 'boolean',
        'completion_percentage' => 'decimal:2',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('was_completed', true);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('played_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('played_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('played_at', now()->month)
            ->whereYear('played_at', now()->year);
    }

    // Accessors
    public function getPlayedAtFormattedAttribute(): string
    {
        return $this->played_at->format('M j, Y \a\t g:i A');
    }

    public function getPlayedAtRelativeAttribute(): string
    {
        return $this->played_at->diffForHumans();
    }

    public function getCompletionPercentageAttribute(): float
    {
        // Return the actual completion_percentage from database if available
        if (isset($this->attributes['completion_percentage'])) {
            return (float) $this->attributes['completion_percentage'];
        }
        // Fallback: calculate from was_completed flag
        return $this->was_completed ? 100 : 0;
    }

    // Static methods - Optimized to prevent N+1 queries
    public static function getTopSongs(User $user, int $limit = 10, int $days = 30)
    {
        return static::where('user_id', $user->id)
            ->where('played_at', '>=', now()->subDays($days))
            ->where('was_completed', true)
            ->with([
                'song:id,title,artist_id,artwork,duration_seconds',
                'song.artist:id,stage_name,avatar,is_verified'
            ])
            ->get()
            ->groupBy('song_id')
            ->map(function ($plays) {
                return [
                    'song' => $plays->first()->song,
                    'play_count' => $plays->count(),
                    'total_duration' => $plays->sum('duration_played_seconds'),
                ];
            })
            ->sortByDesc('play_count')
            ->take($limit)
            ->values();
    }

    public static function getTopArtists(User $user, int $limit = 10, int $days = 30)
    {
        return static::where('user_id', $user->id)
            ->where('played_at', '>=', now()->subDays($days))
            ->where('was_completed', true)
            ->with([
                'song:id,title,artist_id',
                'song.artist:id,stage_name,avatar,is_verified,bio'
            ])
            ->get()
            ->groupBy('song.artist_id')
            ->map(function ($plays) {
                return [
                    'artist' => $plays->first()->song->artist,
                    'play_count' => $plays->count(),
                    'total_duration' => $plays->sum('duration_played_seconds'),
                ];
            })
            ->sortByDesc('play_count')
            ->take($limit)
            ->values();
    }

    /**
     * Get user listening statistics with optimized queries
     */
    public static function getUserListeningStats(User $user, int $days = 30): array
    {
        $baseQuery = static::where('user_id', $user->id)
            ->where('played_at', '>=', now()->subDays($days));

        return [
            'total_plays' => (clone $baseQuery)->count(),
            'completed_plays' => (clone $baseQuery)->where('was_completed', true)->count(),
            'total_duration' => (clone $baseQuery)->sum('duration_played_seconds'),
            'unique_songs' => (clone $baseQuery)->distinct('song_id')->count(),
            'unique_artists' => (clone $baseQuery)
                ->join('songs', 'play_histories.song_id', '=', 'songs.id')
                ->distinct()
                ->count('songs.artist_id'),
        ];
    }

    // Backward compatibility accessor for 'duration_played_seconds'
    public function getPlayDurationSecondsAttribute(): int
    {
        return $this->attributes['duration_played_seconds'] ?? 0;
    }

    // Backward compatibility accessor - was_completed maps to completed
    public function getWasCompletedAttribute(): ?bool
    {
        return $this->attributes['completed'] ?? null;
    }

    // Backward compatibility accessor - was_skipped maps to skipped
    public function getWasSkippedAttribute(): ?bool
    {
        return $this->attributes['skipped'] ?? null;
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();
    }
}
