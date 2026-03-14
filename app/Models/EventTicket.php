<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EventTicket extends Model
{
    use HasFactory;

    protected $table = 'event_tickets';

    protected $fillable = [
        'uuid',
        'event_id',
        'name',
        'description',
        'price_ugx',
        'price_credits',
        'is_free',
        'quantity_total',
        'quantity_sold',
        'quantity_reserved',
        'min_per_order',
        'max_per_order',
        'sale_starts_at',
        'sale_ends_at',
        'is_active',
        'sort_order',
        // Loyalty tier fields
        'required_loyalty_tier',
        'tier_early_access_hours',
        'tier_discounts',
    ];

    protected $casts = [
        'price_ugx' => 'decimal:2',
        'price_credits' => 'decimal:2',
        'quantity_total' => 'integer',
        'quantity_sold' => 'integer',
        'quantity_reserved' => 'integer',
        'min_per_order' => 'integer',
        'max_per_order' => 'integer',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'is_active' => 'boolean',
        'is_free' => 'boolean',
        'tier_discounts' => 'array',
        'tier_early_access_hours' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Str::uuid();
            }
        });
    }

    // Relationships
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class, 'ticket_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(EventAttendee::class, 'ticket_id')
            ->whereNotIn('status', [EventAttendee::STATUS_CANCELLED, EventAttendee::STATUS_NO_SHOW]);
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereRaw('(quantity_total IS NULL OR quantity_total - quantity_sold - quantity_reserved > 0)');
            })
            ->where(function ($q) {
                $q->where('sale_starts_at', '<=', now())
                    ->orWhereNull('sale_starts_at');
            })
            ->where(function ($q) {
                $q->where('sale_ends_at', '>=', now())
                    ->orWhereNull('sale_ends_at');
            });
    }

    public function scopeOnSale(Builder $query): Builder
    {
        return $query->available();
    }

    public function scopeByPriceAsc(Builder $query): Builder
    {
        return $query->orderBy('price_ugx', 'asc');
    }

    public function scopeByPriceDesc(Builder $query): Builder
    {
        return $query->orderBy('price_ugx', 'desc');
    }

    public function scopeBySortOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('price_ugx', 'asc');
    }

    // Accessors
    public function getQuantityAvailableAttribute()
    {
        $reserved = (int) ($this->quantity_reserved ?? 0);

        if ($this->quantity_total === null) {
            return null; // Unlimited
        }

        return max(0, $this->quantity_total - $this->quantity_sold - $reserved);
    }

    public function getTicketTypeAttribute(): string
    {
        return $this->name;
    }

    public function getPriceAttribute(): float
    {
        return (float) ($this->price_ugx ?? 0);
    }

    public function getSalesStartAtAttribute()
    {
        return $this->sale_starts_at;
    }

    public function getSalesEndAtAttribute()
    {
        return $this->sale_ends_at;
    }

    public function isSoldOut(): bool
    {
        if ($this->quantity_total === null) {
            return false; // Unlimited tickets
        }

        return $this->quantityAvailable <= 0;
    }

    public function isOnSale(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->sale_starts_at && $now->isBefore($this->sale_starts_at)) {
            return false;
        }

        if ($this->sale_ends_at && $now->isAfter($this->sale_ends_at)) {
            return false;
        }

        return ! $this->isSoldOut();
    }

    public function isValidOrderQuantity(int $quantity): bool
    {
        if ($quantity < $this->min_per_order) {
            return false;
        }

        if ($this->max_per_order !== null && $quantity > $this->max_per_order) {
            return false;
        }

        return true;
    }

    public function reserve(int $quantity): void
    {
        $this->increment('quantity_reserved', $quantity);
    }

    public function releaseReservation(int $quantity): void
    {
        $this->decrement('quantity_reserved', $quantity);
    }

    public function sell(int $quantity): void
    {
        $this->increment('quantity_sold', $quantity);
        $this->decrement('quantity_reserved', $quantity);
    }

    public function getFormattedPriceAttribute(): string
    {
        if (($this->price_ugx ?? 0) == 0) {
            return 'Free';
        }

        return 'UGX '.number_format((float) $this->price_ugx, 0);
    }

    public function getAvailabilityStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->is_sold_out) {
            return 'sold_out';
        }

        $now = now();

        if ($this->sale_starts_at && $now->isBefore($this->sale_starts_at)) {
            return 'not_yet_available';
        }

        if ($this->sale_ends_at && $now->isAfter($this->sale_ends_at)) {
            return 'sales_ended';
        }

        return 'available';
    }

    public function getAvailabilityMessageAttribute(): string
    {
        return match ($this->availability_status) {
            'inactive' => 'This ticket type is currently inactive',
            'sold_out' => 'Sold Out',
            'not_yet_available' => 'Sales start '.$this->sale_starts_at->format('M j, Y \a\t g:i A'),
            'sales_ended' => 'Sales ended '.$this->sale_ends_at->format('M j, Y \a\t g:i A'),
            'available' => $this->quantity_available ?
                ($this->quantity_available.' remaining') :
                'Available',
            default => 'Unknown status'
        };
    }

    public function getSalesProgressAttribute(): float
    {
        if ($this->quantity_available === null) {
            return 0; // Can't calculate progress for unlimited tickets
        }

        if ($this->quantity_available == 0) {
            return 0;
        }

        return ($this->quantity_sold / $this->quantity_total) * 100;
    }

    public function getTotalRevenueAttribute(): float
    {
        return $this->attendees()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');
    }

    // Helper Methods
    public function canPurchase(int $quantity = 1): bool
    {
        if (! $this->isOnSale()) {
            return false;
        }

        if ($quantity > $this->max_per_order) {
            return false;
        }

        if ($this->quantity_available !== null && $quantity > $this->quantity_available) {
            return false;
        }

        return true;
    }

    public function purchase(User $user, int $quantity = 1, array $metadata = []): EventAttendee
    {
        if (! $this->canPurchase($quantity)) {
            throw new \Exception('Cannot purchase this ticket');
        }

        // Generate unique ticket code
        $ticketCode = strtoupper(Str::random(10));

        // Create attendee record
        $attendee = $this->attendees()->create([
            'event_id' => $this->event_id,
            'ticket_id' => $this->id,
            'user_id' => $user->id,
            'confirmation_code' => $ticketCode,
            'status' => ($this->price_ugx ?? 0) > 0 ? 'pending' : 'confirmed',
            'quantity' => $quantity,
            'amount_paid' => ($this->price_ugx ?? 0) * $quantity,
            'price_paid_ugx' => ($this->price_ugx ?? 0) * $quantity,
            'payment_status' => ($this->price_ugx ?? 0) > 0 ? 'pending' : 'completed',
            'attendee_metadata' => array_merge($metadata, [
                'unit_price' => $this->price_ugx ?? 0,
                'ticket_type' => $this->name,
            ]),
        ]);

        // Update sold quantity
        $this->increment('quantity_sold', $quantity);

        return $attendee;
    }

    public function refund(EventAttendee $attendee): bool
    {
        if ($attendee->ticket_id !== $this->id) {
            return false;
        }

        if ($attendee->payment_status !== 'completed') {
            return false;
        }

        // Update attendee status
        $attendee->update([
            'status' => 'cancelled',
            'payment_status' => 'refunded',
        ]);

        // Decrease sold quantity
        $quantity = $attendee->attendee_metadata['quantity'] ?? 1;
        $this->decrement('quantity_sold', $quantity);

        return true;
    }

    public function getEarlyBirdSavings(): ?float
    {
        // If this is an early bird ticket, calculate savings vs regular price
        $regularTicket = $this->event->tickets()
            ->where('name', 'LIKE', '%General%')
            ->where('id', '!=', $this->id)
            ->first();

        if ($regularTicket && $regularTicket->price_ugx > $this->price_ugx) {
            return (float) $regularTicket->price_ugx - (float) $this->price_ugx;
        }

        return null;
    }

    public function isEarlyBird(): bool
    {
        return str_contains(strtolower($this->name), 'early') ||
               str_contains(strtolower($this->name), 'bird');
    }

    public function isVIP(): bool
    {
        return str_contains(strtolower($this->name), 'vip') ||
               str_contains(strtolower($this->name), 'premium');
    }

    public function getTypeClassAttribute(): string
    {
        if ($this->isVIP()) {
            return 'vip';
        }

        if ($this->isEarlyBird()) {
            return 'early-bird';
        }

        if (($this->price_ugx ?? 0) == 0) {
            return 'free';
        }

        return 'general';
    }

    // Loyalty Tier Access Methods

    /**
     * Check if this ticket requires a loyalty tier for purchase
     */
    public function requiresLoyaltyTier(): bool
    {
        return ! empty($this->required_loyalty_tier);
    }

    /**
     * Check if a user can purchase this ticket based on tier
     */
    public function userCanPurchase(\App\Models\User $user): bool
    {
        if (! $this->requiresLoyaltyTier()) {
            return true;
        }

        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        $access = $tierService->canPurchaseTicket($user, $this);

        return $access['can_access'] ?? false;
    }

    /**
     * Get tier access details for a user
     */
    public function getTierAccessForUser(\App\Models\User $user): array
    {
        $tierService = app(\App\Services\Loyalty\TierAccessService::class);

        return $tierService->canPurchaseTicket($user, $this);
    }

    /**
     * Get the discounted price for a user's tier
     */
    public function getPriceForUser(\App\Models\User $user): float
    {
        if (! $this->tier_discounts) {
            return (float) ($this->price_ugx ?? 0);
        }

        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        $access = $tierService->canPurchaseTicket($user, $this);

        if (isset($access['discount']['discounted_price'])) {
            return $access['discount']['discounted_price'];
        }

        return (float) ($this->price_ugx ?? 0);
    }

    /**
     * Check if user has early access to this ticket
     */
    public function userHasEarlyAccess(\App\Models\User $user): bool
    {
        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        $earlyAccess = $tierService->hasEarlyAccess($user, $this);

        return $earlyAccess['has_early_access'] ?? false;
    }

    /**
     * Scope to filter tickets accessible by a user's tier
     */
    public function scopeAccessibleByUser($query, \App\Models\User $user)
    {
        // Get user's memberships
        $memberships = \App\Models\Loyalty\LoyaltyCardMember::where('user_id', $user->id)
            ->pluck('tier', 'loyalty_card_id')
            ->toArray();

        return $query->where(function ($q) use ($memberships) {
            // Tickets with no tier requirement
            $q->whereNull('required_loyalty_tier');

            // For tier-restricted tickets, we need to check via the event's loyalty card
            // This is a simplified scope - full checking is done in TierAccessService
            if (! empty($memberships)) {
                $q->orWhereIn('required_loyalty_tier', array_values($memberships));
            }
        });
    }
}
