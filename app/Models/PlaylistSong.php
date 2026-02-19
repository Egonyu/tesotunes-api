<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistSong extends Model
{
    protected $table = 'playlist_songs';

    protected $fillable = [
        'playlist_id',
        'song_id',
        'added_by',
        'position',
        'added_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'added_at' => 'datetime',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
