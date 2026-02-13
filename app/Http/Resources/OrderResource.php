<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,

            // Payment
            'payment_method' => $this->payment_method,
            'payment_provider' => $this->payment_provider,
            'transaction_id' => $this->transaction_id,
            'currency' => $this->currency ?? 'UGX',

            // Totals
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'shipping_amount' => $this->shipping_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'credit_amount' => $this->credit_amount,

            // Dual currency
            'total_ugx' => $this->when($this->total_ugx !== null, $this->total_ugx),
            'total_credits' => $this->when($this->total_credits !== null, $this->total_credits),

            // Shipping
            'shipping_address' => $this->shipping_address,
            'billing_address' => $this->billing_address,
            'shipping_method' => $this->shipping_method,
            'tracking_number' => $this->tracking_number,
            'shipping_provider' => $this->shipping_provider,

            // Notes
            'customer_notes' => $this->customer_notes,
            'admin_notes' => $this->when($request->user()?->is_admin ?? false, $this->admin_notes),

            // Relationships
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'store' => new StoreResource($this->whenLoaded('store')),
            'customer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ]),

            // Timestamps
            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
