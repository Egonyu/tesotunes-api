<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A partner offer on the Edula timeline: a store's time-boxed deal that a
 * member claims, paying its price in credits, and redeems at the counter.
 */
class Deal extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'uuid',
        'store_id',
        'title',
        'slug',
        'discount_label',
        'terms',
        'image',
        'town',
        'credits_price',
        'ugx_value',
        'valid_from',
        'valid_until',
        'max_claims',
        'claims_count',
        'redemptions_count',
        'status',
        'published_at',
    ];

    protected $casts = [
        'credits_price' => 'integer',
        'ugx_value' => 'decimal:2',
        'max_claims' => 'integer',
        'claims_count' => 'integer',
        'redemptions_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'published_at' => 'datetime',
    ];

    protected static function newFactory(): DealFactory
    {
        return DealFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Deal $deal) {
            $deal->uuid ??= (string) Str::uuid();
            $deal->slug ??= Str::slug($deal->title).'-'.Str::lower(Str::random(5));
            $deal->town ??= $deal->store?->city;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(DealClaim::class);
    }

    /** Deals a member may claim right now: published, in their window, with claims left. */
    public function scopeLive(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('published_at')->where('published_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $at))
            ->where(fn (Builder $q) => $q->whereNull('max_claims')->orWhereColumn('claims_count', '<', 'max_claims'))
            ->whereHas('store', fn (Builder $q) => $q->where('status', Store::STATUS_ACTIVE));
    }

    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_ACTIVE
            && $this->published_at !== null && $this->published_at->lte($at)
            && ($this->valid_from === null || $this->valid_from->lte($at))
            && ($this->valid_until === null || $this->valid_until->gt($at))
            && $this->claimsLeft() !== 0
            && $this->store?->status === Store::STATUS_ACTIVE;
    }

    /** Null means unlimited. */
    public function claimsLeft(): ?int
    {
        return $this->max_claims === null ? null : max(0, $this->max_claims - $this->claims_count);
    }

    public function isFree(): bool
    {
        return $this->credits_price <= 0;
    }

    public function claimFor(User $user): ?DealClaim
    {
        return $this->claims()->where('user_id', $user->id)->latest('id')->first();
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return Str::startsWith($this->image, ['http://', 'https://'])
            ? $this->image
            : rtrim(config('app.url'), '/').'/storage/'.ltrim($this->image, '/');
    }
}
