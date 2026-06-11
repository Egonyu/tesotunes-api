<?php

namespace App\Models\Commerce;

use App\Models\User;
use Database\Factories\Commerce\SettlementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A single seller-side money event in the unified commerce ledger.
 * See docs/architecture/COMMERCE_CORE.md for lifecycle and invariants.
 */
class Settlement extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_PAID_OUT = 'paid_out';

    public const STATUS_REVERSED = 'reversed';

    public const VERTICAL_MUSIC = 'music';

    public const VERTICAL_STORE = 'store';

    public const VERTICAL_EVENTS = 'events';

    public const VERTICAL_PROMOTIONS = 'promotions';

    /**
     * Amounts and lifecycle fields are NOT fillable — they are computed and
     * transitioned exclusively by SettlementService.
     */
    protected $fillable = [
        'beneficiary_user_id',
        'vertical',
        'kind',
        'source_type',
        'source_id',
        'hold_until',
        'metadata',
    ];

    protected static function newFactory(): SettlementFactory
    {
        return SettlementFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Settlement $settlement) {
            $settlement->uuid = $settlement->uuid ?: (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'gross_ugx' => 'decimal:2',
            'fee_ugx' => 'decimal:2',
            'net_ugx' => 'decimal:2',
            'gross_credits' => 'integer',
            'fee_credits' => 'integer',
            'net_credits' => 'integer',
            'hold_until' => 'datetime',
            'cleared_at' => 'datetime',
            'paid_out_at' => 'datetime',
            'reversed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function payout(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCleared(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLEARED);
    }

    public function scopeForBeneficiary(Builder $query, User $user): Builder
    {
        return $query->where('beneficiary_user_id', $user->id);
    }

    public function scopeDueForClearance(Builder $query, ?\DateTimeInterface $asOf = null): Builder
    {
        $asOf ??= now();

        return $query->pending()->where(function (Builder $builder) use ($asOf) {
            $builder->whereNull('hold_until')->orWhere('hold_until', '<=', $asOf);
        });
    }
}
