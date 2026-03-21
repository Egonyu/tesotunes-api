<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventDiscountCode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class EventDiscountCodeService
{
    public function resolveForSelections(Event $event, Collection $tickets, string $code): EventDiscountCode
    {
        $normalizedCode = strtoupper(trim($code));

        /** @var EventDiscountCode|null $discountCode */
        $discountCode = $event->discountCodes()
            ->active()
            ->where('code', $normalizedCode)
            ->first();

        if (! $discountCode) {
            $this->fail('Invalid discount code for this event.');
        }

        if (! $discountCode->isCurrentlyRedeemable()) {
            $this->fail('This discount code is not active right now.');
        }

        if (! $discountCode->appliesToAnyTicket($tickets)) {
            $this->fail('This discount code does not apply to the selected ticket tiers.');
        }

        $eligibleSubtotal = $tickets
            ->filter(fn ($ticket) => $discountCode->appliesToTicketId((int) $ticket->id))
            ->sum(fn ($ticket) => (float) ($ticket->price_ugx ?? 0));

        if ($eligibleSubtotal <= 0) {
            $this->fail('This discount code cannot be applied to the selected tickets.');
        }

        return $discountCode;
    }

    public function validateForQuote(Event $event, Collection $tickets, array $selections, string $code): array
    {
        $discountCode = $this->resolveForSelections($event, $tickets, $code);

        $orderSubtotal = $this->calculateOrderSubtotal($tickets, $selections);

        if (! $discountCode->meetsMinimumOrder($orderSubtotal)) {
            $minimum = number_format((float) ($discountCode->min_order_amount_ugx ?? 0), 0);
            $this->fail("This code requires a minimum order of UGX {$minimum}.");
        }

        return [
            'discount_code' => $discountCode,
            'message' => 'Discount code applied successfully.',
        ];
    }

    public function incrementUsage(EventDiscountCode $discountCode): void
    {
        $discountCode->increment('usage_count');
    }

    public function decrementUsageByPaymentMetadata(array $metadata): void
    {
        $discountCodeId = data_get($metadata, 'discount_code.id');
        if (! $discountCodeId) {
            return;
        }

        /** @var EventDiscountCode|null $discountCode */
        $discountCode = EventDiscountCode::query()->find($discountCodeId);
        if (! $discountCode || $discountCode->usage_count <= 0) {
            return;
        }

        $discountCode->decrement('usage_count');
    }

    private function calculateOrderSubtotal(Collection $tickets, array $selections): float
    {
        $ticketsById = $tickets->keyBy('id');

        return round(collect($selections)->sum(function (array $selection) use ($ticketsById) {
            $ticket = $ticketsById->get((int) $selection['ticket_tier_id']);

            return ((float) ($ticket?->price_ugx ?? 0)) * (int) $selection['quantity'];
        }), 2);
    }

    private function fail(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
        ], 422));
    }
}
