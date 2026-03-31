<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaUploadSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'artist_id',
        'kind',
        'original_filename',
        'content_type',
        'file_extension',
        'size_bytes',
        'part_size_bytes',
        'total_parts',
        'disk',
        'target_key',
        'chunk_prefix',
        'status',
        'metadata',
        'expires_at',
        'completed_at',
        'aborted_at',
        'consumed_at',
        'last_error',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'part_size_bytes' => 'integer',
        'total_parts' => 'integer',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'aborted_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
