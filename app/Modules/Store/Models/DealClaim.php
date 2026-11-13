<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One member's claim on a deal. The token is what the QR code carries; it
 * is single use and dies with the claim's expiry.
 */
class DealClaim extends Model
{
    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid',
        'deal_id',
        'user_id',
        'token',
        'credits_spent',
        'status',
        'expires_at',
        'redeemed_at',
        'redeemed_by_user_id',
        'merchant_ugx',
        'platform_fee_ugx',
        'credit_rate_ugx',
    ];

    protected $casts = [
        'credits_spent' => 'integer',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'merchant_ugx' => 'decimal:2',
        'platform_fee_ugx' => 'decimal:2',
        'credit_rate_ugx' => 'decimal:4',
    ];

    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::creating(function (DealClaim $claim) {
            $claim->uuid ??= (string) Str::uuid();
            $claim->token ??= Str::upper(Str::random(12)).'-'.Str::random(20);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function scopeStale(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query->where('status', self::STATUS_CLAIMED)->whereNotNull('expires_at')->where('expires_at', '<=', $at ?? now());
    }

    public function isRedeemable(?\DateTimeInterface $at = null): bool
    {
        return $this->status === self::STATUS_CLAIMED && ($this->expires_at === null || $this->expires_at->gt($at ?? now()));
    }

    /** The claim as the owner sees it, token included, for the QR sheet. */
    public function toOwnerArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'token' => $this->token,
            'status' => $this->status,
            'credits_spent' => $this->credits_spent,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'deal' => [
                'uuid' => $this->deal?->uuid,
                'title' => $this->deal?->title,
                'discount_label' => $this->deal?->discount_label,
                'terms' => $this->deal?->terms,
                'store_name' => $this->deal?->store?->name,
                'store_slug' => $this->deal?->store?->slug,
                'town' => $this->deal?->town,
                'valid_until' => $this->deal?->valid_until?->toIso8601String(),
            ],
        ];
    }
}
