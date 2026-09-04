<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

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

    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'amount',
        'balance_after',
        'source',
        'description',
        'reference',
        'referenceable_type',
        'referenceable_id',
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

    /*
     * Movement types.
     *
     * 'earn' and 'spend' used to sit beside 'earned' and 'spent' as a second
     * way to say the same thing. Nothing ever wrote them — all 201 rows on
     * production use the longer forms — but they were quietly poisonous,
     * because every query had to remember to match both and three places
     * forgot: getRate looked up columns that did not exist, getUserCreditStats
     * summed 'earn' and returned zero for every account, and the amount
     * accessor tested 'spend' so every withdrawal displayed as a credit.
     *
     * A synonym nobody writes is not harmless; it is a trap that only springs
     * on whoever forgets it exists.
     */
    const TYPE_EARNED = 'earned';

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

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creditable(): MorphTo
    {
        return $this->referenceable();
    }

    // Scopes
    public function scopeEarned($query)
    {
        return $query->where('type', self::TYPE_EARNED);
    }

    public function scopeSpent($query)
    {
        return $query->where('type', self::TYPE_SPENT);
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
            now()->endOfWeek(),
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
    /**
     * The amount with the sign a reader expects, e.g. "-1,000 credits".
     *
     * This once tested `type === 'spend'`, a spelling nothing writes, so every
     * spend was shown as a credit — money leaving the wallet reading as money
     * arriving. That synonym is now retired, so there is one form to match.
     */
    public function getFormattedAmountAttribute(): string
    {
        $isOutgoing = $this->type === self::TYPE_SPENT || $this->amount < 0;

        return ($isOutgoing ? '-' : '+').number_format(abs($this->amount), 0).' credits';
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'earned' => '💰',
            'spent' => '💸',
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
        return match ($this->source) {
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
        return match ($this->type) {
            // Matched only 'earn' and 'spend', so the forms actually in use
            // fell through to the default and were right by luck.
            'earned' => 'Earned',
            'spent' => 'Spent',
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
            'reference' => 'stream_'.uniqid(),
            'referenceable_type' => $creditableType ?? 'App\\Models\\Song',
            'referenceable_id' => $creditableId ?? 0,
        ]);
    }
}
