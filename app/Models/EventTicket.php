<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class EventTicket extends Model
{
    use HasFactory;

    protected $table = 'event_ticket_types';

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
        // Legacy fields
        'ticket_type',
        'price',
        'quantity_available',
        'sales_start_at',
        'sales_end_at',
        'perks',
    ];

    protected $casts = [
        'price_ugx' => 'decimal:2',
        'price_credits' => 'decimal:2',
        'price' => 'decimal:2',
        'quantity_total' => 'integer',
        'quantity_sold' => 'integer',
        'quantity_reserved' => 'integer',
        'quantity_available' => 'integer',
        'min_per_order' => 'integer',
        'max_per_order' => 'integer',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'sales_start_at' => 'datetime',
        'sales_end_at' => 'datetime',
        'is_active' => 'boolean',
        'is_free' => 'boolean',
        'perks' => 'array',
        'tier_discounts' => 'array',
        'tier_early_access_hours' => 'integer',
        'sort_order' => 'integer'
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
        return $this->hasMany(EventAttendee::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(EventAttendee::class)->where('attendance_type', 'ticket_purchase');
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
                        $q->where('quantity_available', '>', 0)
                          ->orWhereNull('quantity_available'); // unlimited
                    })
                    ->where(function ($q) {
                        $q->where('sales_start_at', '<=', now())
                          ->orWhereNull('sales_start_at');
                    })
                    ->where(function ($q) {
                        $q->where('sales_end_at', '>=', now())
                          ->orWhereNull('sales_end_at');
                    });
    }

    public function scopeOnSale(Builder $query): Builder
    {
        return $query->available();
    }

    public function scopeByPriceAsc(Builder $query): Builder
    {
        return $query->orderBy('price', 'asc');
    }

    public function scopeByPriceDesc(Builder $query): Builder
    {
        return $query->orderBy('price', 'desc');
    }

    public function scopeBySortOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('price', 'asc');
    }

    // Accessors
    public function getQuantityAvailableAttribute()
    {
        if ($this->quantity_total === null) {
            return null; // Unlimited
        }

        return max(0, $this->quantity_total - $this->quantity_sold - $this->quantity_reserved);
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
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->sale_starts_at && $now->isBefore($this->sale_starts_at)) {
            return false;
        }

        if ($this->sale_ends_at && $now->isAfter($this->sale_ends_at)) {
            return false;
        }

        return !$this->isSoldOut();
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
        if ($this->price == 0) {
            return 'Free';
        }

        return 'UGX ' . number_format($this->price, 0);
    }

    public function getAvailabilityStatusAttribute(): string
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        if ($this->is_sold_out) {
            return 'sold_out';
        }

        $now = now();

        if ($this->sales_start_at && $now->isBefore($this->sales_start_at)) {
            return 'not_yet_available';
        }

        if ($this->sales_end_at && $now->isAfter($this->sales_end_at)) {
            return 'sales_ended';
        }

        return 'available';
    }

    public function getAvailabilityMessageAttribute(): string
    {
        return match($this->availability_status) {
            'inactive' => 'This ticket type is currently inactive',
            'sold_out' => 'Sold Out',
            'not_yet_available' => 'Sales start ' . $this->sales_start_at->format('M j, Y \a\t g:i A'),
            'sales_ended' => 'Sales ended ' . $this->sales_end_at->format('M j, Y \a\t g:i A'),
            'available' => $this->quantity_available ?
                ($this->quantity_remaining . ' remaining') :
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

        return ($this->quantity_sold / $this->quantity_available) * 100;
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
        if (!$this->is_available) {
            return false;
        }

        if ($quantity > $this->max_per_order) {
            return false;
        }

        if ($this->quantity_available !== null && $quantity > $this->quantity_remaining) {
            return false;
        }

        return true;
    }

    public function purchase(User $user, int $quantity = 1, array $metadata = []): EventAttendee
    {
        if (!$this->canPurchase($quantity)) {
            throw new \Exception('Cannot purchase this ticket');
        }

        // Generate unique ticket code
        $ticketCode = $this->generateTicketCode();

        // Create attendee record
        $attendee = $this->attendees()->create([
            'event_id' => $this->event_id,
            'user_id' => $user->id,
            'ticket_code' => $ticketCode,
            'attendance_type' => 'ticket_purchase',
            'status' => $this->price > 0 ? 'pending' : 'confirmed',
            'amount_paid' => $this->price * $quantity,
            'payment_status' => $this->price > 0 ? 'pending' : 'completed',
            'attendee_metadata' => array_merge($metadata, [
                'quantity' => $quantity,
                'unit_price' => $this->price,
                'ticket_type' => $this->ticket_type
            ])
        ]);

        // Update sold quantity
        $this->increment('quantity_sold', $quantity);

        return $attendee;
    }

    public function refund(EventAttendee $attendee): bool
    {
        if ($attendee->event_ticket_id !== $this->id) {
            return false;
        }

        if ($attendee->payment_status !== 'completed') {
            return false;
        }

        // Update attendee status
        $attendee->update([
            'status' => 'cancelled',
            'payment_status' => 'refunded'
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
                             ->where('ticket_type', 'LIKE', '%General%')
                             ->where('id', '!=', $this->id)
                             ->first();

        if ($regularTicket && $regularTicket->price > $this->price) {
            return $regularTicket->price - $this->price;
        }

        return null;
    }

    public function isEarlyBird(): bool
    {
        return str_contains(strtolower($this->ticket_type), 'early') ||
               str_contains(strtolower($this->ticket_type), 'bird');
    }

    public function isVIP(): bool
    {
        return str_contains(strtolower($this->ticket_type), 'vip') ||
               str_contains(strtolower($this->ticket_type), 'premium');
    }

    public function getTypeClassAttribute(): string
    {
        if ($this->isVIP()) {
            return 'vip';
        }

        if ($this->isEarlyBird()) {
            return 'early-bird';
        }

        if ($this->price == 0) {
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
        return !empty($this->required_loyalty_tier);
    }

    /**
     * Check if a user can purchase this ticket based on tier
     */
    public function userCanPurchase(\App\Models\User $user): bool
    {
        if (!$this->requiresLoyaltyTier()) {
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
        if (!$this->tier_discounts) {
            return $this->price_ugx ?? $this->price ?? 0;
        }

        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        $access = $tierService->canPurchaseTicket($user, $this);
        
        if (isset($access['discount']['discounted_price'])) {
            return $access['discount']['discounted_price'];
        }

        return $this->price_ugx ?? $this->price ?? 0;
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
            if (!empty($memberships)) {
                $q->orWhereIn('required_loyalty_tier', array_values($memberships));
            }
        });
    }
}