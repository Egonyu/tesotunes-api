<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Distribution extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'song_id',
        'artist_id',
        'platform_code',
        'platform_name',
        'status',
        'platform_url',
        'platform_id',
        'platform_metadata',
        'distribution_metadata',
        'live_date',
        'removed_date',
        'removal_reason',
        'removal_requested_at',
        'error_message',
        'rejection_reason',
        'total_streams',
        'total_revenue',
        'last_synced',
        'last_updated',
    ];

    protected $casts = [
        'platform_metadata' => 'array',
        'distribution_metadata' => 'array',
        'live_date' => 'datetime',
        'removed_date' => 'datetime',
        'removal_requested_at' => 'datetime',
        'last_synced' => 'datetime',
        'last_updated' => 'datetime',
        'total_streams' => 'integer',
        'total_revenue' => 'decimal:2',
    ];

    // --- Relationships ---

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    // --- Scopes ---

    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform_code', $platform);
    }

    public function scopeForArtist($query, int $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    // --- Accessors ---

    public function getIsLiveAttribute(): bool
    {
        return $this->status === 'live';
    }

    public function getFormattedRevenueAttribute(): string
    {
        return '$' . number_format($this->total_revenue ?? 0, 2);
    }

    public function getFormattedStreamsAttribute(): string
    {
        return number_format($this->total_streams ?? 0);
    }
}
