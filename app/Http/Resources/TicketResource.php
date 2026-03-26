<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'ticket_number' => $this->confirmation_code,
            'qr_code' => $this->qr_code,
            'status' => $this->status,
            'holder_name' => $this->attendee_name,
            'holder_email' => $this->attendee_email,
            'holder_phone' => $this->attendee_phone,
            'price_paid' => (float) ($this->price_paid_ugx ?? 0),
            'price_paid_credits' => (float) ($this->price_paid_credits ?? 0),
            'amount_paid' => (float) ($this->amount_paid ?? $this->price_paid_ugx ?? 0),
            'quantity' => (int) ($this->quantity ?? 1),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_reference' => $this->payment_reference,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'event' => $this->when($this->relationLoaded('event') && $this->event, function () {
                return [
                    'id' => $this->event->id,
                    'title' => $this->event->title,
                    'starts_at' => $this->event->starts_at?->toIso8601String(),
                    'ends_at' => $this->event->ends_at?->toIso8601String(),
                    'artwork' => StorageHelper::artworkUrl($this->event->artwork),
                    'venue_name' => $this->event->venue_name,
                    'city' => $this->event->city,
                    'ticketing_mode' => $this->event->ticketing_mode,
                ];
            }),
            'ticket_tier' => $this->when($this->relationLoaded('ticket') && $this->ticket, function () {
                return (new EventTicketTierResource($this->ticket))->resolve();
            }),
            'metadata' => [
                'order_id' => data_get($this->attendee_metadata, 'order_id'),
                'attribution' => data_get($this->attendee_metadata, 'attribution'),
                'fee_breakdown' => data_get($this->attendee_metadata, 'fee_breakdown'),
                'wallet_actions' => data_get($this->attendee_metadata, 'wallet_actions'),
                'support_cases' => data_get($this->attendee_metadata, 'support_cases'),
            ],
        ];
    }
}
