<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlaybackPosition extends Model
{
    protected $fillable = [
        'user_id',
        'song_id',
        'position_seconds',
    ];

    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
