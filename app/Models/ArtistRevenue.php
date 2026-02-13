<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ArtistRevenue Model
 * 
 * Tracks all revenue earned by artists.
 * 
 * Database columns:
 * - id, artist_id, revenue_type, revenue_source_type, revenue_source_id,
 *   amount_ugx, amount_usd, currency, platform_fee, net_amount,
 *   revenue_date, status, created_at, updated_at
 */
class ArtistRevenue extends Model
{
    use HasFactory;

    protected $fillable = [
        'artist_id',
        'revenue_type',
        'revenue_source_type',
        'revenue_source_id',
        'amount_ugx',
        'amount_usd',
        'currency',
        'platform_fee',
        'net_amount',
        'revenue_date',
        'status',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'amount_usd' => 'decimal:6',
        'platform_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'revenue_date' => 'date',
    ];

    // Type constants
    const TYPE_STREAM = 'stream';
    const TYPE_DOWNLOAD = 'download';
    const TYPE_DISTRIBUTION = 'distribution';
    const TYPE_TIP = 'tip';
    const TYPE_SALE = 'sale';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PAID = 'paid';

    // Relationships
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Get the related revenue source (song, album, etc)
     */
    public function revenueSource()
    {
        return $this->morphTo('revenue_source', 'revenue_source_type', 'revenue_source_id');
    }

    // Helper Methods
    public function getRevenueTypeDisplayAttribute(): string
    {
        return match($this->revenue_type) {
            'stream' => 'Streaming Revenue',
            'download' => 'Download',
            'distribution' => 'Platform Distribution',
            'tip' => 'Fan Tip',
            'sale' => 'Sale',
            default => ucfirst($this->revenue_type)
        };
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'UGX ' . number_format($this->net_amount, 0);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isConfirmed(): bool
    {
        return in_array($this->status, ['confirmed', 'paid']);
    }

    // Scopes
    public function scopeForArtist($query, $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('revenue_type', $type);
    }

    public function scopeStreaming($query)
    {
        return $query->where('revenue_type', self::TYPE_STREAM);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('revenue_date', now()->month)
                     ->whereYear('revenue_date', now()->year);
    }

    public function scopeLastMonth($query)
    {
        return $query->whereMonth('revenue_date', now()->subMonth()->month)
                     ->whereYear('revenue_date', now()->subMonth()->year);
    }

    public function scopeThisWeek($query)
    {
        return $query->where('revenue_date', '>=', now()->startOfWeek());
    }

    public function scopeConfirmed($query)
    {
        return $query->whereIn('status', ['confirmed', 'paid']);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}