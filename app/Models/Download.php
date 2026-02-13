<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Song;
use App\Models\Playlist;
use App\Models\User;

class Download extends Model
{
    use HasFactory;

    // Disable timestamps - table doesn't have created_at/updated_at
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'downloadable_type',
        'downloadable_id',
        'quality',
        'format',
        'file_size_bytes',
        'ip_address',
        'downloaded_at',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the downloadable model (Song, Playlist, etc.)
     */
    public function downloadable()
    {
        return $this->morphTo();
    }

    /**
     * Get the song if this is a song download
     */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'downloadable_id');
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'downloadable_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForSong($query, $songId)
    {
        return $query->where('downloadable_type', Song::class)
            ->where('downloadable_id', $songId);
    }

    public function scopeForPlaylist($query, $playlistId)
    {
        return $query->where('downloadable_type', Playlist::class)
            ->where('downloadable_id', $playlistId);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('downloaded_at', today());
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('downloaded_at', $date);
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('download_count');
        $this->update(['last_downloaded_at' => now()]);
    }
}