<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
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
            'artwork' => StorageHelper::url($this->artwork),
            'banner' => StorageHelper::url($this->banner),

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
            'ticket_price' => $this->ticket_price,
            'currency' => $this->currency ?? 'UGX',
            'attendee_limit' => $this->attendee_limit,
            'tickets_sold' => (int) ($this->tickets_sold ?? 0),
            'ticket_tiers' => $this->when($this->relationLoaded('tickets'), function () {
                return $this->tickets->map(function ($tier) {
                    return [
                        'id' => $tier->id,
                        'name' => $tier->name,
                        'description' => $tier->description,
                        'price' => $tier->price_ugx,
                        'price_ugx' => $tier->price_ugx,
                        'price_credits' => $tier->price_credits,
                        'is_free' => (bool) $tier->is_free,
                        'quantity' => $tier->quantity_total,
                        'quantity_total' => $tier->quantity_total,
                        'quantity_sold' => $tier->quantity_sold,
                        'available' => $tier->quantity_total - $tier->quantity_sold - ($tier->quantity_reserved ?? 0),
                        'max_per_order' => $tier->max_per_order,
                        'sales_start_date' => $tier->sale_starts_at,
                        'sales_end_date' => $tier->sale_ends_at,
                        'is_active' => (bool) $tier->is_active,
                        'required_loyalty_tier' => $tier->required_loyalty_tier,
                        'tier_early_access_hours' => $tier->tier_early_access_hours,
                    ];
                });
            }),

            // Flags
            'is_featured' => (bool) $this->is_featured,
            'is_published' => (bool) $this->is_published,
            'requires_approval' => (bool) $this->requires_approval,

            // Stats
            'attendee_count' => (int) ($this->attendee_count ?? 0),
            'rating_average' => $this->rating_average,
            'review_count' => (int) ($this->review_count ?? 0),

            // Organizer
            'organizer' => $this->when($this->relationLoaded('organizer') && $this->organizer, function () {
                return [
                    'id' => $this->organizer->id,
                    'name' => $this->organizer->name,
                    'avatar' => StorageHelper::avatarUrl($this->organizer->avatar, $this->organizer->name),
                ];
            }),

            // Tags
            'tags' => $this->tags,

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'registration_deadline' => $this->registration_deadline?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // API links
            'links' => [
                'self' => url("/api/admin/events/{$this->id}"),
                'registrations' => url("/api/admin/events/{$this->id}/registrations"),
            ],
        ];
    }
}
