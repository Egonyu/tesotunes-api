<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * CreditTransaction Model
 * 
 * Tracks all wallet credit transactions for users.
 * 
 * Database columns:
 * - id, user_id, type, amount, balance_after, description, reference,
 *   creditable_type, creditable_id, metadata, created_at, updated_at
 */
class CreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'source',
        'description',
        'reference',
        'reference_type',
        'reference_id',
        'creditable_type',
        'creditable_id',
        'related_user_id',
        'metadata',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    // Type constants (must match database enum values)
    const TYPE_EARN = 'earn';
    const TYPE_EARNED = 'earned';
    const TYPE_SPEND = 'spend';
    const TYPE_SPENT = 'spent';
    const TYPE_REFUND = 'refund';
    const TYPE_BONUS = 'bonus';
    const TYPE_GIFT = 'gift';
    const TYPE_PURCHASE = 'purchase';
    const TYPE_STREAM = 'stream';
    const TYPE_TRANSFERRED = 'transferred';

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    public function creditable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeEarned($query)
    {
        return $query->whereIn('type', [self::TYPE_EARN, self::TYPE_EARNED]);
    }

    public function scopeSpent($query)
    {
        return $query->whereIn('type', [self::TYPE_SPEND, self::TYPE_SPENT]);
    }

    public function scopeStreaming($query)
    {
        return $query->where('type', self::TYPE_STREAM);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('processed_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Helper methods
    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->type === 'spend' || $this->amount < 0 ? '-' : '+';
        return $prefix . 'UGX ' . number_format(abs($this->amount), 0);
    }

    public function getTypeIconAttribute(): string
    {
        return match($this->type) {
            'earn', 'earned' => '💰',
            'spend', 'spent' => '💸',
            'refund' => '🔄',
            'bonus' => '🎁',
            'gift' => '🎀',
            'purchase' => '🛒',
            'stream' => '🎵',
            'transferred' => '↔️',
            default => '💳'
        };
    }

    public function getSourceDescriptionAttribute(): string
    {
        return match($this->source) {
            'listening' => 'Music listening',
            'daily_login' => 'Daily login bonus',
            'referral' => 'Referral bonus',
            'song_play_complete' => 'Completed song play',
            'transfer_out' => 'Transfer sent',
            'transfer_in' => 'Transfer received',
            'bonus' => 'Bonus credits',
            default => ucfirst(str_replace('_', ' ', $this->source ?? 'Unknown'))
        };
    }

    public function getTypeDescriptionAttribute(): string
    {
        return match($this->type) {
            'earn' => 'Earned',
            'spend' => 'Spent',
            'refund' => 'Refund',
            'bonus' => 'Bonus',
            'gift' => 'Gift',
            'purchase' => 'Purchase',
            'stream' => 'Streaming Revenue',
            default => ucfirst(str_replace('_', ' ', $this->type))
        };
    }

    /**
     * Create a streaming revenue transaction
     */
    public static function createStreamingRevenue(
        int $userId,
        float $amount,
        float $balanceAfter,
        string $description,
        ?string $creditableType = null,
        ?int $creditableId = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'type' => self::TYPE_STREAM,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'description' => $description,
            'reference' => 'stream_' . uniqid(),
            'creditable_type' => $creditableType ?? 'App\\Models\\Song',
            'creditable_id' => $creditableId ?? 0,
            'metadata' => $metadata,
        ]);
    }
}
