<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\Featurable;
use Illuminate\Support\Str;

class Playlist extends Model
{
    use HasFactory, SoftDeletes, Featurable;

    protected $fillable = [
        'uuid',
        'user_id',
        'category_id',
        'name',            // Database column is 'name' not 'title'
        'slug',
        'description',
        'artwork',
        'visibility',
        'is_collaborative',
        'is_featured',
        'is_system',
        'song_count',      // Database column is 'song_count' not 'total_tracks'
        'total_duration_seconds',
        'play_count',
        'follower_count',
    ];

    protected $casts = [
        'is_collaborative' => 'boolean',
        'is_featured' => 'boolean',
        'is_system' => 'boolean',
        'song_count' => 'integer',
        'total_duration_seconds' => 'integer',
        'play_count' => 'integer',
        'follower_count' => 'integer',
    ];

    /**
     * Attributes to append to JSON serialization
     * This ensures 'title' and 'total_tracks' are included in API responses
     */
    protected $appends = [
        'title',
        'total_tracks',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($playlist) {
            if (empty($playlist->uuid)) {
                $playlist->uuid = (string) Str::uuid();
            }
            
            // Auto-generate slug from name
            if (!$playlist->slug && $playlist->name) {
                $playlist->slug = Str::slug($playlist->name);
                
                // Ensure uniqueness
                $originalSlug = $playlist->slug;
                $count = 1;
                while (static::where('slug', $playlist->slug)->where('user_id', $playlist->user_id)->exists()) {
                    $playlist->slug = $originalSlug . '-' . $count++;
                }
            }
        });
    }

    /**
     * Get the route key for the model.
     * Uses slug for clean URLs on frontend, but admin routes can still use ID via explicit binding.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        // Check if the current request is for admin routes - use ID for admin
        $request = request();
        if ($request && str_starts_with($request->path(), 'admin')) {
            return 'id';
        }
        
        return 'slug';
    }

    /**
     * Resolve route binding using either slug or ID.
     * This allows both /playlists/my-playlist and /admin/playlists/123 to work.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // If an integer is passed, search by ID (for admin)
        if (is_numeric($value)) {
            return $this->where('id', $value)->firstOrFail();
        }
        
        // Otherwise search by slug (for frontend)
        return $this->where('slug', $value)->firstOrFail();
    }

    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Alias for owner() - for compatibility with admin views
     */
    public function user(): BelongsTo
    {
        return $this->owner();
    }

    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'playlist_songs')
            ->withPivot(['added_by', 'position', 'added_at'])
            ->orderBy('playlist_songs.position');
    }

    public function playlistSongs(): HasMany
    {
        return $this->hasMany(PlaylistSong::class)->orderBy('position');
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(PlaylistCollaborator::class);
    }

    public function activeCollaborators(): HasMany
    {
        return $this->hasMany(PlaylistCollaborator::class)
            ->where('status', 'accepted');
    }

    // Polymorphic relationships
    public function followers()
    {
        return $this->morphMany(UserFollow::class, 'following');  // Laravel will auto-detect following_type and following_id
    }

    public function activities()
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function shares()
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('visibility', 'private');
    }

    public function scopeCollaborative($query)
    {
        return $query->where('is_collaborative', true);
    }

    public function scopeAccessibleBy($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('user_id', $user->id)
                ->orWhereHas('collaborators', function ($collab) use ($user) {
                    $collab->where('user_id', $user->id)
                        ->where('status', 'accepted');
                });
        });
    }

    // Accessors
    /**
     * Get title attribute (alias for name for backward compatibility)
     */
    public function getTitleAttribute(): ?string
    {
        return $this->name;
    }

    /**
     * Set title attribute (alias for name for backward compatibility)
     */
    public function setTitleAttribute($value): void
    {
        $this->attributes['name'] = $value;
    }

    /**
     * Get total_tracks attribute (alias for song_count for backward compatibility)
     */
    public function getTotalTracksAttribute(): int
    {
        return $this->song_count ?? 0;
    }

    /**
     * Set total_tracks attribute (alias for song_count for backward compatibility)
     */
    public function setTotalTracksAttribute($value): void
    {
        $this->attributes['song_count'] = $value;
    }

    public function getArtworkUrlAttribute(): ?string
    {
        if ($this->artwork) {
            return \App\Helpers\StorageHelper::artworkUrl($this->artwork, '/images/default-playlist-artwork.svg');
        }

        // Generate mosaic artwork from first 4 songs
        // TODO: Implement playlist.artwork route for dynamic mosaic generation
        // $firstSongs = $this->songs()->limit(4)->get();
        // if ($firstSongs->count() > 0) {
        //     return route('playlist.artwork', $this->id);
        // }

        return asset('images/default-playlist-artwork.svg');
    }

    public function getTotalDurationFormattedAttribute(): string
    {
        $hours = floor($this->total_duration / 3600);
        $minutes = floor(($this->total_duration % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%d hr %d min', $hours, $minutes);
        }
        return sprintf('%d min', $minutes);
    }

    public function getIsLikedByUserAttribute(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        return $this->likes()->where('user_id', auth()->id())->exists();
    }

    public function getIsFollowedByUserAttribute(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        return $this->followers()->where('follower_id', auth()->id())->exists();
    }

    // Helper methods
    public function canBeAccessedBy(User $user): bool
    {
        if ($this->visibility === 'public') {
            return true;
        }

        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists();
    }

    public function canBeEditedBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        if (!$this->is_collaborative) {
            return false;
        }

        return $this->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->whereIn('permission', ['edit', 'admin'])
            ->exists();
    }

    public function addSong(Song $song, User $addedBy, ?int $position = null): void
    {
        if ($position === null) {
            $position = $this->playlistSongs()->max('position') + 1;
        }

        // Shift existing songs if inserting in middle
        if ($position <= $this->playlistSongs()->max('position')) {
            $this->playlistSongs()
                ->where('position', '>=', $position)
                ->increment('position');
        }

        PlaylistSong::create([
            'playlist_id' => $this->id,
            'song_id' => $song->id,
            'added_by' => $addedBy->id,
            'position' => $position,
        ]);

        $this->updateCounts();
        $this->notifyFollowersOfActivity('song_added', $song, $addedBy);
    }

    public function removeSong(Song $song): void
    {
        $playlistSong = $this->playlistSongs()
            ->where('song_id', $song->id)
            ->first();

        if ($playlistSong) {
            $position = $playlistSong->position;
            $playlistSong->delete();

            // Shift remaining songs
            $this->playlistSongs()
                ->where('position', '>', $position)
                ->decrement('position');

            $this->updateCounts();
        }
    }

    public function reorderSongs(array $songIds): void
    {
        foreach ($songIds as $index => $songId) {
            $this->playlistSongs()
                ->where('song_id', $songId)
                ->update(['position' => $index + 1]);
        }
    }

    public function updateCounts(): void
    {
        $songs = $this->songs;
        
        $this->update([
            'total_tracks' => $songs->count(),
            'total_duration_seconds' => $songs->sum('duration_seconds'),
        ]);
    }

    // Offline functionality for African market
    public function getOfflineDownloadUrlAttribute(): string
    {
        return route('playlist.download', $this->id);
    }

    public function isAvailableForOfflineDownload(): bool
    {
        // Only allow download if majority of songs are free
        $totalSongs = $this->songs()->count();
        $freeSongs = $this->songs()->where('is_free', true)->count();

        return $freeSongs >= ($totalSongs * 0.8); // 80% of songs must be free
    }

    public function getDownloadableTracksAttribute(): array
    {
        return $this->songs()
            ->where('status', 'published')
            ->where('is_free', true)
            ->get()
            ->map(function ($song) {
                return [
                    'id' => $song->id,
                    'title' => $song->title,
                    'artist' => $song->artist->stage_name,
                    'audio_url' => $song->audio_url,
                    'compressed_url' => $song->compressed_audio_url,
                    'artwork' => $song->artwork_url,
                    'duration' => $song->duration,
                ];
            })
            ->toArray();
    }

    private function notifyFollowersOfActivity(string $activityType, Song $song, User $user): void
    {
        $followers = $this->followers()->with('follower')->get();

        foreach ($followers as $follow) {
            $follow->follower->notifications()->create([
                'type' => 'playlist_activity',
                'title' => 'Playlist Updated',
                'message' => "{$user->name} added \"{$song->title}\" to \"{$this->name}\"",
                'data' => [
                    'playlist_id' => $this->id,
                    'song_id' => $song->id,
                    'activity_type' => $activityType,
                ],
                'action_url' => route('playlist.show', $this->slug),
            ]);
        }
    }
}