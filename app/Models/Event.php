<?php

namespace App\Models;

use App\Traits\HasReviews;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes, HasReviews;

    protected $fillable = [
        'uuid',
        'organizer_id',
        'organizer_type',
        'event_location_id',
        'title',
        'slug',
        'description',
        'is_virtual',
        'virtual_link',
        'artwork',
        'banner',
        'starts_at',
        'ends_at',
        'timezone',
        'doors_open_at',
        'status',
        'visibility',
        'category',
        'tags',
        'requires_approval',
        'attendee_limit',
        'cancellation_policy',
        // Loyalty tier access fields
        'required_loyalty_tier',
        'loyalty_card_id',
        'tier_early_access_hours',
        'hide_from_non_qualifying',
        // Legacy fields (keep for backward compatibility)
        'user_id',
        'artist_id',
        'event_type',
        'venue_name',
        'venue_address',
        'city',
        'country',
        'latitude',
        'longitude',
        'capacity',
        'total_tickets',
        'tickets_sold',
        'attendee_count',
        'is_free',
        'ticket_price',
        'currency',
        'cover_image',
        'featured_image',
        'gallery',
        'requirements',
        'contact_info',
        'website',
        'social_links',
        'is_featured',
        'is_published',
        'published_at',
        'registration_deadline',
        'refund_policy',
        // Rating fields
        'rating_average',
        'review_count',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'registration_deadline' => 'datetime',
        'doors_open_at' => 'datetime',
        'rating_average' => 'decimal:2',
        'review_count' => 'integer',
        'is_free' => 'boolean',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_virtual' => 'boolean',
        'requires_approval' => 'boolean',
        'hide_from_non_qualifying' => 'boolean',
        'tier_early_access_hours' => 'integer',
        'loyalty_card_id' => 'integer',
        'gallery' => 'array',
        'tags' => 'array',
        'requirements' => 'array',
        'contact_info' => 'array',
        'social_links' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7'
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
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function location()
    {
        return $this->belongsTo(EventLocation::class, 'event_location_id');
    }

    public function statistics()
    {
        return $this->hasOne(EventStatistics::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    public function activities()
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('starts_at', '>=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeNearLocation($query, $latitude, $longitude, $radius = 50)
    {
        return $query->selectRaw("*,
            ( 6371 * acos( cos( radians(?) ) *
              cos( radians( latitude ) ) *
              cos( radians( longitude ) - radians(?) ) +
              sin( radians(?) ) *
              sin( radians( latitude ) ) ) ) AS distance",
            [$latitude, $longitude, $latitude])
            ->having('distance', '<', $radius)
            ->orderBy('distance');
    }

    // Accessors - Backward compatibility for legacy column names
    public function getStartDateAttribute()
    {
        return $this->starts_at;
    }

    public function getEndDateAttribute()
    {
        return $this->ends_at;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'published';
    }

    public function getIsPastAttribute(): bool
    {
        return $this->ends_at < now();
    }

    public function getIsUpcomingAttribute(): bool
    {
        return $this->starts_at >= now();
    }

    public function getAvailableTicketsAttribute(): int
    {
        if (!$this->capacity) {
            return -1; // Unlimited
        }

        $soldTickets = $this->attendees()->where('status', 'confirmed')->count();
        return max(0, $this->capacity - $soldTickets);
    }

    public function getIsSoldOutAttribute(): bool
    {
        return $this->capacity && $this->available_tickets <= 0;
    }

    public function getFeaturedImageUrlAttribute(): ?string
    {
        return $this->featured_image ? \App\Helpers\StorageHelper::url($this->featured_image) : null;
    }

    public function getFormattedDateAttribute(): string
    {
        if ($this->starts_at->isSameDay($this->ends_at)) {
            return $this->starts_at->format('M j, Y');
        }

        return $this->starts_at->format('M j') . ' - ' . $this->ends_at->format('M j, Y');
    }

    public function getFormattedTimeAttribute(): string
    {
        if ($this->starts_at && $this->ends_at) {
            return $this->starts_at->format('g:i A') . ' - ' . $this->ends_at->format('g:i A');
        }

        return $this->starts_at ? $this->starts_at->format('g:i A') : 'TBA';
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->venue_name,
            $this->venue_address,
            $this->city,
            $this->country
        ]);

        return implode(', ', $parts);
    }

    // Helper methods
    public function isAttendedBy(User $user): bool
    {
        return $this->attendees()
                   ->where('user_id', $user->id)
                   ->whereIn('status', ['confirmed', 'pending'])
                   ->exists();
    }

    public function getAttendeeStatus(User $user): ?string
    {
        $attendee = $this->attendees()
                        ->where('user_id', $user->id)
                        ->first();

        return $attendee?->status;
    }

    public function canUserRegister(User $user): bool
    {
        if ($this->is_past || !$this->is_active) {
            return false;
        }

        if ($this->registration_deadline && now()->isAfter($this->registration_deadline)) {
            return false;
        }

        if ($this->isAttendedBy($user)) {
            return false;
        }

        if ($this->is_sold_out) {
            return false;
        }

        return true;
    }

    public function register(User $user, array $data = []): EventAttendee
    {
        if (!$this->canUserRegister($user)) {
            throw new \Exception('Cannot register for this event');
        }

        return $this->attendees()->create([
            'user_id' => $user->id,
            'status' => $this->is_free ? 'confirmed' : 'pending',
            'registration_data' => $data,
            'registered_at' => now()
        ]);
    }

    public function publish(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    public function unpublish(): void
    {
        $this->update([
            'status' => 'draft'
        ]);
    }

    public function cancel(string $reason = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason
        ]);

        // Notify attendees
        $this->attendees()->each(function ($attendee) use ($reason) {
            $attendee->user->notifications()->create([
                'type' => 'event_cancelled',
                'title' => 'Event Cancelled',
                'message' => "The event '{$this->title}' has been cancelled." . ($reason ? " Reason: {$reason}" : ''),
                'data' => ['event_id' => $this->id],
                'action_url' => route('frontend.events.show', $this->id)
            ]);
        });
    }

    // Ticketing helper methods
    public function availableTickets(): HasMany
    {
        return $this->tickets()->available();
    }

    public function onSaleTickets(): HasMany
    {
        return $this->tickets()->onSale()->bySortOrder();
    }

    public function getTotalRevenueAttribute(): float
    {
        return $this->attendees()
                   ->where('payment_status', 'completed')
                   ->sum('amount_paid');
    }

    public function getConfirmedAttendeesCountAttribute(): int
    {
        return $this->attendees()
                   ->whereIn('status', ['confirmed', 'checked_in'])
                   ->count();
    }

    public function getPendingAttendeesCountAttribute(): int
    {
        return $this->attendees()->where('status', 'pending')->count();
    }

    public function getCheckedInAttendeesCountAttribute(): int
    {
        return $this->attendees()->where('status', 'checked_in')->count();
    }

    public function getTotalTicketsSoldAttribute(): int
    {
        return $this->tickets()->sum('quantity_sold');
    }

    public function hasTicketsAvailable(): bool
    {
        return $this->onSaleTickets()->exists();
    }

    public function getCheapestTicketPriceAttribute(): ?float
    {
        return $this->onSaleTickets()->min('price');
    }

    public function getMostExpensiveTicketPriceAttribute(): ?float
    {
        return $this->onSaleTickets()->max('price');
    }

    public function getPriceRangeAttribute(): string
    {
        $cheapest = $this->cheapest_ticket_price;
        $expensive = $this->most_expensive_ticket_price;

        if (!$cheapest && !$expensive) {
            return 'No tickets available';
        }

        if ($cheapest == 0 && $expensive == 0) {
            return 'Free';
        }

        if ($cheapest == $expensive) {
            return 'UGX ' . number_format($cheapest, 0);
        }

        if ($cheapest == 0) {
            return 'Free - UGX ' . number_format($expensive, 0);
        }

        return 'UGX ' . number_format($cheapest, 0) . ' - UGX ' . number_format($expensive, 0);
    }

    public function createDefaultTicket(): EventTicket
    {
        return $this->tickets()->create([
            'ticket_type' => 'General Admission',
            'description' => 'Standard event access',
            'price' => $this->base_price ?? 0,
            'quantity_available' => $this->capacity,
            'max_per_order' => 10,
            'is_active' => true,
            'sort_order' => 1
        ]);
    }

    public function getTicketSalesStatsAttribute(): array
    {
        $tickets = $this->tickets()->withCount('attendees')->get();

        return [
            'total_ticket_types' => $tickets->count(),
            'total_tickets_available' => $tickets->sum('quantity_available'),
            'total_tickets_sold' => $tickets->sum('quantity_sold'),
            'total_revenue' => $this->total_revenue,
            'tickets_remaining' => $tickets->sum(function($ticket) {
                return $ticket->quantity_available ? $ticket->quantity_remaining : 0;
            }),
            'sales_progress' => $this->capacity ?
                ($this->total_tickets_sold / $this->capacity) * 100 : 0
        ];
    }

    // Loyalty Tier Access Methods

    /**
     * Relationship to the loyalty card for tier-based access
     */
    public function loyaltyCard()
    {
        return $this->belongsTo(\App\Models\Loyalty\LoyaltyCard::class, 'loyalty_card_id');
    }

    /**
     * Check if this event requires a loyalty tier for access
     */
    public function requiresLoyaltyTier(): bool
    {
        return !empty($this->required_loyalty_tier);
    }

    /**
     * Check if a user meets the tier requirements for this event
     */
    public function userMeetsTierRequirement(User $user): bool
    {
        if (!$this->requiresLoyaltyTier()) {
            return true;
        }

        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        $access = $tierService->canAccessEvent($user, $this);
        
        return $access['can_access'] ?? false;
    }

    /**
     * Get tier access details for a user
     */
    public function getTierAccessForUser(User $user): array
    {
        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        return $tierService->canAccessEvent($user, $this);
    }

    /**
     * Scope to filter events by tier accessibility for a user
     */
    public function scopeAccessibleByUser($query, User $user)
    {
        $tierService = app(\App\Services\Loyalty\TierAccessService::class);
        return $tierService->scopeAccessibleEvents($query, $user);
    }

    /**
     * Scope to filter events that have tier requirements
     */
    public function scopeTierRestricted($query)
    {
        return $query->whereNotNull('required_loyalty_tier');
    }

    /**
     * Scope to filter public events (no tier requirement)
     */
    public function scopePublicAccess($query)
    {
        return $query->whereNull('required_loyalty_tier');
    }

    /**
     * Check if event should be hidden from users who don't meet tier
     */
    public function shouldHideFromUser(User $user): bool
    {
        if (!$this->hide_from_non_qualifying) {
            return false;
        }

        return !$this->userMeetsTierRequirement($user);
    }
}
