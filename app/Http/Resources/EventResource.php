<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use App\Models\EventWaitlistEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $organizer = $this->resolveOrganizerForResource();
        $ticketingSummary = $this->buildTicketingSummary();
        $waitlistCount = $this->relationLoaded('waitlistEntries')
            ? $this->waitlistEntries->where('status', EventWaitlistEntry::STATUS_ACTIVE)->count()
            : (int) $this->waitlistEntries()->where('status', EventWaitlistEntry::STATUS_ACTIVE)->count();
        $waitlistJoined = false;

        if ($request->user()) {
            $waitlistJoined = $this->relationLoaded('waitlistEntries')
                ? $this->waitlistEntries->contains(fn ($entry) => $entry->user_id === $request->user()->id && $entry->status === EventWaitlistEntry::STATUS_ACTIVE)
                : $this->waitlistEntries()->where('user_id', $request->user()->id)->where('status', EventWaitlistEntry::STATUS_ACTIVE)->exists();
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'event_type' => $this->event_type,
            'status' => $this->status,

            // Media
            'artwork' => StorageHelper::artworkUrl($this->artwork),
            'banner' => StorageHelper::artworkUrl($this->banner),

            // Schedule
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'doors_open_at' => $this->doors_open_at?->toIso8601String(),
            'timezone' => $this->timezone,

            // Venue
            'is_virtual' => (bool) $this->is_virtual,
            'virtual_link' => $this->when($this->is_virtual, $this->virtual_link),
            'venue_name' => $this->venue_name,
            'venue_address' => $this->venue_address,
            'city' => $this->city,
            'country' => $this->country,
            'location' => $this->when($this->relationLoaded('location') && $this->location, function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'address' => $this->location->address ?? null,
                    'city' => $this->location->city ?? null,
                ];
            }),

            // Ticketing
            'is_free' => (bool) $this->is_free,
            'ticketing_mode' => $this->ticketing_mode ?? ((bool) $this->is_free ? 'free_rsvp' : 'tesotunes_managed'),
            'ticketing_summary' => $ticketingSummary,
            'ticket_price' => $this->ticket_price,
            'currency' => $this->currency ?? 'UGX',
            'attendee_limit' => $this->attendee_limit,
            'tickets_sold' => (int) ($this->tickets_sold ?? 0),
            'ticket_tiers' => $this->when($this->relationLoaded('tickets'), function () {
                return EventTicketTierResource::collection($this->tickets);
            }),
            'discount_codes' => $this->when($this->relationLoaded('discountCodes'), function () {
                return $this->discountCodes->map(fn ($code) => [
                    'id' => $code->id,
                    'name' => $code->name,
                    'code' => $code->code,
                    'discount_type' => $code->discount_type,
                    'discount_value' => (float) $code->discount_value,
                    'max_discount_ugx' => $code->max_discount_ugx !== null ? (float) $code->max_discount_ugx : null,
                    'usage_limit' => $code->usage_limit,
                    'usage_count' => $code->usage_count,
                    'min_order_amount_ugx' => $code->min_order_amount_ugx !== null ? (float) $code->min_order_amount_ugx : null,
                    'applies_to_ticket_ids' => $code->applies_to_ticket_ids ?? [],
                    'starts_at' => $code->starts_at?->toIso8601String(),
                    'ends_at' => $code->ends_at?->toIso8601String(),
                    'is_active' => (bool) $code->is_active,
                ])->values();
            }),
            'waitlist_count' => $waitlistCount,
            'waitlist_joined' => $waitlistJoined,

            // Flags
            'is_featured' => (bool) $this->is_featured,
            'is_published' => (bool) $this->is_published,
            'requires_approval' => (bool) $this->requires_approval,

            // Stats
            'attendee_count' => (int) ($this->attendee_count ?? 0),
            'rating_average' => $this->rating_average,
            'review_count' => (int) ($this->review_count ?? 0),

            // Organizer
            'organizer' => $this->when($organizer, function () use ($organizer) {
                return [
                    'id' => $organizer->id,
                    'name' => $organizer->name,
                    'slug' => $organizer->username,
                    'avatar' => StorageHelper::avatarUrl($organizer->avatar, $organizer->name),
                    'artist_id' => $organizer->artist?->id,
                ];
            }),
            'staff_members' => $this->when($this->relationLoaded('staffMembers'), function () use ($organizer) {
                $members = $this->staffMembers->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'user_id' => $member->user_id,
                        'role' => $member->role,
                        'role_label' => str($member->role)->replace('_', ' ')->title()->value(),
                        'notes' => $member->notes,
                        'assigned_at' => $member->created_at?->toIso8601String(),
                        'user' => $member->relationLoaded('user') && $member->user ? [
                            'id' => $member->user->id,
                            'name' => $member->user->name,
                            'email' => $member->user->email,
                            'username' => $member->user->username,
                            'avatar' => StorageHelper::avatarUrl($member->user->avatar, $member->user->name),
                        ] : null,
                    ];
                });

                if ($organizer) {
                    $members->prepend([
                        'id' => 'organizer-'.$organizer->id,
                        'user_id' => $organizer->id,
                        'role' => 'organizer',
                        'role_label' => 'Organizer',
                        'notes' => 'Primary event owner',
                        'assigned_at' => $this->created_at?->toIso8601String(),
                        'user' => [
                            'id' => $organizer->id,
                            'name' => $organizer->name,
                            'email' => $organizer->email,
                            'username' => $organizer->username,
                            'avatar' => StorageHelper::avatarUrl($organizer->avatar, $organizer->name),
                        ],
                    ]);
                }

                return $members->values();
            }),
            'organizer_identity' => [
                'organizer_id' => $this->organizer_id ?? $organizer?->id,
                'legacy_user_id' => $this->user_id,
                'legacy_artist_id' => $this->artist_id ?? $organizer?->artist?->id,
                'organizer_type' => $this->organizer_type ?? 'user',
            ],

            // Tags
            'tags' => $this->tags,

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'registration_deadline' => $this->registration_deadline?->toIso8601String(),
            'refund_policy' => $this->refund_policy,
            'cancellation_policy' => $this->cancellation_policy,
            'requirements' => $this->requirements ?? [],
            'contact_info' => $this->contact_info ?? [],
            'website' => $this->website,
            'social_links' => $this->social_links ?? [],
            'marketing_settings' => $this->marketing_settings ?? [],
            'promotion_requests' => $this->when($this->relationLoaded('promotionRequests'), function () {
                return $this->promotionRequests->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'uuid' => $request->uuid,
                        'promotion_slug' => $request->promotion_slug,
                        'promotion_title' => $request->promotion_title,
                        'promotion_type' => $request->promotion_type,
                        'promotion_platform' => $request->promotion_platform,
                        'price_credits' => (float) $request->price_credits,
                        'price_ugx' => (float) $request->price_ugx,
                        'status' => $request->status,
                        'request_notes' => $request->request_notes,
                        'moderation_notes' => $request->moderation_notes,
                        'featured_image_url' => $request->featured_image_url,
                        'requested_at' => $request->requested_at?->toIso8601String(),
                        'moderated_at' => $request->moderated_at?->toIso8601String(),
                        'requested_by' => $request->relationLoaded('requestedBy') && $request->requestedBy ? [
                            'id' => $request->requestedBy->id,
                            'name' => $request->requestedBy->name,
                            'email' => $request->requestedBy->email,
                        ] : null,
                        'moderated_by' => $request->relationLoaded('moderatedBy') && $request->moderatedBy ? [
                            'id' => $request->moderatedBy->id,
                            'name' => $request->moderatedBy->name,
                            'email' => $request->moderatedBy->email,
                        ] : null,
                    ];
                })->values();
            }),
            'operations' => [
                'registration_deadline' => $this->registration_deadline?->toIso8601String(),
                'refund_policy' => $this->refund_policy,
                'cancellation_policy' => $this->cancellation_policy,
                'support_email' => data_get($this->contact_info, 'support_email'),
                'support_phone' => data_get($this->contact_info, 'support_phone'),
                'age_restriction' => data_get($this->contact_info, 'age_restriction'),
                'door_notes' => data_get($this->contact_info, 'door_notes'),
                'tax_vat_notes' => data_get($this->contact_info, 'tax_vat_notes'),
                'requirements' => $this->requirements ?? [],
                'website' => $this->website,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => route('api.events.show', ['id' => $this->id]),
                'artist' => route('api.artist.events.show', ['id' => $this->id]),
                'admin' => route('api.admin.events.show', ['id' => $this->id]),
                'registrations' => route('api.admin.events.registrations', ['id' => $this->id]),
            ],
        ];
    }

    private function resolveOrganizerForResource(): ?User
    {
        if ($this->relationLoaded('organizer') && $this->organizer) {
            return $this->organizer->relationLoaded('artist')
                ? $this->organizer
                : $this->organizer->loadMissing('artist');
        }

        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->relationLoaded('artist')
                ? $this->user
                : $this->user->loadMissing('artist');
        }

        if (method_exists($this->resource, 'resolveOrganizerUser')) {
            $resolved = $this->resource->resolveOrganizerUser();

            if ($resolved) {
                return $resolved->relationLoaded('artist')
                    ? $resolved
                    : $resolved->loadMissing('artist');
            }
        }

        return null;
    }

    private function buildTicketingSummary(): array
    {
        $mode = $this->ticketing_mode ?? ((bool) $this->is_free ? 'free_rsvp' : 'tesotunes_managed');
        $tickets = $this->relationLoaded('tickets')
            ? $this->tickets->loadMissing('channelAllocations')
            : $this->tickets()->with('channelAllocations')->get();

        $totalCapacity = 0;
        $tesotunesSold = 0;
        $tesotunesAvailable = 0;
        $externalAllocated = 0;
        $hasUnlimitedCapacity = false;

        foreach ($tickets as $ticket) {
            if ($ticket->quantity_total === null) {
                $hasUnlimitedCapacity = true;
            } else {
                $totalCapacity += (int) $ticket->quantity_total;
            }

            $tesotunesSold += (int) ($ticket->quantity_sold ?? 0);
            $externalAllocated += (int) $ticket->external_allocated_quantity;

            $available = $ticket->quantity_available;
            if ($available === null) {
                $hasUnlimitedCapacity = true;
            } else {
                $tesotunesAvailable += (int) $available;
            }
        }

        $onlineSellThrough = $totalCapacity > 0
            ? round(($tesotunesSold / max($totalCapacity, 1)) * 100, 2)
            : 0.0;

        return [
            'mode_label' => match ($mode) {
                'hybrid' => 'Tesotunes + external channels',
                'external_only' => 'External or organizer-managed ticketing',
                'free_rsvp' => 'Free RSVP',
                default => 'Tesotunes ticketing',
            },
            'tesotunes_checkout_enabled' => in_array($mode, ['tesotunes_managed', 'hybrid', 'free_rsvp'], true),
            'manual_reconciliation_enabled' => in_array($mode, ['hybrid', 'external_only'], true),
            'has_external_allocations' => $externalAllocated > 0,
            'total_capacity' => $hasUnlimitedCapacity ? null : $totalCapacity,
            'tesotunes_sold' => $tesotunesSold,
            'tesotunes_available' => $hasUnlimitedCapacity ? null : $tesotunesAvailable,
            'external_allocated' => $externalAllocated,
            'online_sell_through_percent' => $onlineSellThrough,
        ];
    }
}
