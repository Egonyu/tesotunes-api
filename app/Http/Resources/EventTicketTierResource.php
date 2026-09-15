<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $available = $this->quantity_available;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) ($this->price_ugx ?? 0),
            'price_ugx' => (float) ($this->price_ugx ?? 0),
            /*
             * What one ticket costs the buyer at checkout, fees included, from
             * the same calculator checkout uses — so the event page can show
             * the real price up front instead of revealing fees at payment.
             */
            'buyer_price_ugx' => $this->buyerPriceUgx(),
            'fees_included_in_price' => $this->relationLoaded('event') && $this->event
                ? $this->event->feeHandling() === \App\Models\Event::FEE_HANDLING_ABSORB
                : null,
            'price_credits' => (float) ($this->price_credits ?? 0),
            'is_free' => (bool) $this->is_free,
            'quantity' => $this->quantity_total,
            'quantity_total' => $this->quantity_total,
            'quantity_sold' => (int) ($this->quantity_sold ?? 0),
            'quantity_reserved' => (int) ($this->quantity_reserved ?? 0),
            'quantity_external_allocated' => (int) ($this->external_allocated_quantity ?? 0),
            'available' => $available,
            'min_per_order' => (int) ($this->min_per_order ?? 1),
            'max_per_order' => (int) ($this->max_per_order ?? 10),
            'sale_starts_at' => $this->sale_starts_at?->toIso8601String(),
            'sale_ends_at' => $this->sale_ends_at?->toIso8601String(),
            'sales_start_date' => $this->sale_starts_at?->toIso8601String(),
            'sales_end_date' => $this->sale_ends_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
            'is_sold_out' => $this->isSoldOut(),
            'is_on_sale' => $this->isOnSale(),
            'required_loyalty_tier' => $this->required_loyalty_tier,
            'tier_early_access_hours' => $this->tier_early_access_hours,
            'availability_status' => $this->availability_status,
            'availability_message' => $this->availability_message,
        ];
    }

    private function buyerPriceUgx(): ?float
    {
        if (! $this->relationLoaded('event') || ! $this->event || (float) ($this->price_ugx ?? 0) <= 0) {
            return (float) ($this->price_ugx ?? 0);
        }

        try {
            // Rates depend on the organiser; loaded once on the shared event.
            $this->event->loadMissing(['organizer.artist', 'user.artist']);
            $quote = app(\App\Services\Events\EventFeeCalculatorService::class)->calculateForTicket($this->resource, 1);

            return (float) $quote['total_amount'];
        } catch (\Throwable) {
            return null;
        }
    }
}
