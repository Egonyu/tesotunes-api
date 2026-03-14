<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\ZengaPayService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventTicketingService
{
    public function __construct(
        private readonly ZengaPayService $zengaPayService,
    ) {}

    public function purchase(User $user, array $validated): array
    {
        return DB::transaction(function () use ($user, $validated) {
            $event = Event::findOrFail($validated['event_id']);
            $ticket = EventTicket::where('event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($validated['ticket_tier_id']);

            $quantity = (int) $validated['quantity'];
            $paymentMethod = $validated['payment_method'];

            if (! $ticket->isOnSale()) {
                $this->failPurchase('This ticket type is not currently available');
            }

            if (! $ticket->isValidOrderQuantity($quantity)) {
                $this->failPurchase([
                    'message' => "Order quantity must be between {$ticket->min_per_order} and {$ticket->max_per_order}",
                ]);
            }

            if (! $ticket->canPurchase($quantity)) {
                $available = $ticket->quantity_available ?? 0;
                $this->failPurchase("Only {$available} tickets remaining");
            }

            $pricePerTicket = (float) $ticket->price_ugx;
            $totalAmount = $pricePerTicket * $quantity;
            $serviceFee = round($totalAmount * 0.05, 2);
            $grandTotal = $totalAmount + $serviceFee;
            $totalCredits = ((int) $ticket->price_credits) * $quantity;
            $orderId = 'ORD-'.strtoupper(Str::random(10));

            $this->assertFundsAvailable($user, $paymentMethod, $grandTotal, $totalCredits, $ticket);
            $this->assertPaymentChannelRequirements($paymentMethod, $validated['phone'] ?? null);

            $payment = $this->createPaymentRecord(
                user: $user,
                event: $event,
                ticket: $ticket,
                paymentMethod: $paymentMethod,
                orderId: $orderId,
                grandTotal: $grandTotal,
                quantity: $quantity,
                phoneNumber: $validated['phone'] ?? null,
            );

            $ticket->reserve($quantity);

            $tickets = [];
            $instantSettlement = in_array($paymentMethod, ['wallet', 'credits'], true);

            for ($i = 0; $i < $quantity; $i++) {
                $ticketNumber = 'TKT-'.strtoupper(Str::random(8));
                $qrData = json_encode([
                    'ticket' => $ticketNumber,
                    'event' => $event->id,
                    'type' => $ticket->name,
                ]);

                $attendee = EventAttendee::create([
                    'uuid' => (string) Str::uuid(),
                    'confirmation_code' => $ticketNumber,
                    'event_id' => $event->id,
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'attendee_name' => $validated['holder_name'] ?? $user->full_name ?? $user->display_name ?? $user->username ?? $user->name,
                    'attendee_email' => $validated['holder_email'] ?? $user->email,
                    'attendee_phone' => $validated['holder_phone'] ?? $user->phone,
                    'price_paid_ugx' => $paymentMethod === 'credits' ? 0 : $pricePerTicket,
                    'price_paid_credits' => $paymentMethod === 'credits' ? (int) $ticket->price_credits : 0,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $payment->payment_reference,
                    'status' => $instantSettlement ? EventAttendee::STATUS_CONFIRMED : EventAttendee::STATUS_PENDING,
                    'confirmed_at' => $instantSettlement ? now() : null,
                    'quantity' => 1,
                    'amount_paid' => $paymentMethod === 'credits' ? 0 : $pricePerTicket,
                    'payment_status' => $instantSettlement ? 'completed' : 'pending',
                    'qr_code' => base64_encode($qrData),
                    'attendee_metadata' => [
                        'order_id' => $orderId,
                        'unit_price' => $pricePerTicket,
                        'service_fee' => $serviceFee,
                        'payment_id' => $payment->id,
                    ],
                ]);

                $tickets[] = [
                    'id' => $attendee->id,
                    'ticket_number' => $ticketNumber,
                    'qr_code' => $attendee->qr_code,
                    'status' => $attendee->status,
                    'tier' => $ticket->name,
                    'price' => $pricePerTicket,
                    'holder_name' => $attendee->attendee_name,
                ];
            }

            if ($instantSettlement) {
                $this->settleInstantPayment($user, $paymentMethod, $grandTotal, $totalCredits);
                $ticket->sell($quantity);
                $payment->markAsCompleted([
                    'provider_reference' => $payment->payment_reference,
                ]);
            } elseif (in_array($paymentMethod, ['mtn_momo', 'airtel_money'], true)) {
                $this->initiateMobileMoneyCollection($payment, $validated['phone'] ?? null);
            }

            return [
                'data' => [
                    'order_id' => $orderId,
                    'tickets' => $tickets,
                    'total_amount' => $grandTotal,
                    'service_fee' => $serviceFee,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $payment->payment_reference,
                    'status' => $instantSettlement ? 'completed' : 'pending_payment',
                ],
                'message' => 'Tickets purchased successfully',
            ];
        });
    }

    public function settlePendingOrderPayment(Payment $payment): void
    {
        if ($payment->payment_type !== 'ticket_purchase') {
            return;
        }

        DB::transaction(function () use ($payment) {
            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $paymentData = $payment->payment_data ?? [];

            if (! empty($paymentData['event_ticketing_settled_at'])) {
                return;
            }

            $attendees = $this->getOrderAttendees($payment)->filter(
                fn (EventAttendee $attendee) => $attendee->status === EventAttendee::STATUS_PENDING
            );

            if ($attendees->isEmpty()) {
                $this->stampPaymentData($payment, ['event_ticketing_settled_at' => now()->toIso8601String()]);

                return;
            }

            $ticket = EventTicket::query()
                ->lockForUpdate()
                ->find($payment->metadata['ticket_id'] ?? $attendees->first()?->ticket_id);

            if ($ticket) {
                $ticket->sell($attendees->count());
            }

            $attendees->each(function (EventAttendee $attendee) use ($payment) {
                $attendee->confirm($payment->payment_reference);
            });

            $this->stampPaymentData($payment, [
                'event_ticketing_settled_at' => now()->toIso8601String(),
                'settled_attendee_count' => $attendees->count(),
            ]);
        });
    }

    public function failPendingOrderPayment(Payment $payment, ?string $reason = null): void
    {
        if ($payment->payment_type !== 'ticket_purchase') {
            return;
        }

        DB::transaction(function () use ($payment, $reason) {
            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $paymentData = $payment->payment_data ?? [];

            if (! empty($paymentData['event_ticketing_failed_at'])) {
                return;
            }

            $attendees = $this->getOrderAttendees($payment)->filter(
                fn (EventAttendee $attendee) => $attendee->status === EventAttendee::STATUS_PENDING
            );

            $ticket = EventTicket::query()
                ->lockForUpdate()
                ->find($payment->metadata['ticket_id'] ?? $attendees->first()?->ticket_id);

            if ($ticket && $attendees->isNotEmpty()) {
                $ticket->releaseReservation($attendees->count());
            }

            $attendees->each(function (EventAttendee $attendee) use ($payment, $reason) {
                $attendee->update([
                    'status' => EventAttendee::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'payment_status' => $payment->status === Payment::STATUS_CANCELLED ? 'cancelled' : 'failed',
                    'notes' => $reason ?: $attendee->notes,
                ]);
            });

            $this->stampPaymentData($payment, [
                'event_ticketing_failed_at' => now()->toIso8601String(),
                'failed_attendee_count' => $attendees->count(),
            ]);
        });
    }

    private function assertFundsAvailable(
        User $user,
        string $paymentMethod,
        float $grandTotal,
        int $totalCredits,
        EventTicket $ticket,
    ): void {
        if ($paymentMethod === 'credits') {
            if ((int) $ticket->price_credits <= 0) {
                $this->failPurchase('This ticket does not support credit payment');
            }

            if ((int) $user->credits < $totalCredits) {
                $this->failPurchase('Insufficient credits');
            }

            return;
        }

        if ($paymentMethod === 'wallet' && (float) $user->ugx_balance < $grandTotal) {
            $this->failPurchase('Insufficient wallet balance');
        }
    }

    private function assertPaymentChannelRequirements(string $paymentMethod, ?string $phoneNumber): void
    {
        if (in_array($paymentMethod, ['mtn_momo', 'airtel_money'], true) && blank($phoneNumber)) {
            $this->failPurchase('Phone number is required for mobile money payments');
        }
    }

    private function createPaymentRecord(
        User $user,
        Event $event,
        EventTicket $ticket,
        string $paymentMethod,
        string $orderId,
        float $grandTotal,
        int $quantity,
        ?string $phoneNumber,
    ): Payment {
        $payment = new Payment([
            'user_id' => $user->id,
            'payable_type' => Event::class,
            'payable_id' => $event->id,
            'payment_reference' => 'PAY-'.strtoupper(Str::random(12)),
            'transaction_reference' => $orderId,
            'payment_method' => $paymentMethod,
            'provider' => in_array($paymentMethod, ['mtn_momo', 'airtel_money'], true) ? 'zengapay' : $paymentMethod,
            'payment_type' => 'ticket_purchase',
            'phone_number' => $phoneNumber,
            'description' => "Ticket purchase for event #{$event->id} - Order {$orderId}",
            'metadata' => [
                'order_id' => $orderId,
                'event_id' => $event->id,
                'ticket_id' => $ticket->id,
                'quantity' => $quantity,
                'payment_channel' => $paymentMethod,
            ],
        ]);

        $payment->forceFill([
            'amount' => $grandTotal,
            'currency' => 'UGX',
            'status' => in_array($paymentMethod, ['wallet', 'credits'], true) ? Payment::STATUS_COMPLETED : Payment::STATUS_PENDING,
            'completed_at' => in_array($paymentMethod, ['wallet', 'credits'], true) ? now() : null,
        ])->save();

        return $payment;
    }

    private function settleInstantPayment(User $user, string $paymentMethod, float $grandTotal, int $totalCredits): void
    {
        if ($paymentMethod === 'wallet') {
            $user->decrement('ugx_balance', $grandTotal);

            return;
        }

        if ($paymentMethod === 'credits') {
            $user->decrement('credits', $totalCredits);
        }
    }

    private function initiateMobileMoneyCollection(Payment $payment, ?string $phoneNumber): void
    {
        try {
            $this->zengaPayService->processPayment($payment, [
                'phone_number' => $phoneNumber,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ZengaPay ticket payment initiation failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getOrderAttendees(Payment $payment): Collection
    {
        return EventAttendee::query()
            ->where('payment_reference', $payment->payment_reference)
            ->orderBy('id')
            ->get();
    }

    private function stampPaymentData(Payment $payment, array $data): void
    {
        $payment->forceFill([
            'payment_data' => array_merge($payment->payment_data ?? [], $data),
        ])->save();
    }

    private function failPurchase(string|array $payload): never
    {
        if (is_string($payload)) {
            $payload = ['message' => $payload];
        }

        throw new HttpResponseException(response()->json($payload, 422));
    }
}
