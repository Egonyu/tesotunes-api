<?php

namespace App\Modules\Contributions\Models;

use App\Models\Song;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An artist's per-song opt-in that releases the song's lyrics into the
 * translation task pool. Withdrawable; rights stay with the artist.
 */
class SongLyricOptIn extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $table = 'song_lyric_optins';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tasks_generated' => 'integer',
            'opted_in_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function optedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opted_in_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
