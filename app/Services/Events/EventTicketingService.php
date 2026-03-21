<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventDiscountCode;
use App\Models\EventTicket;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\ZengaPayService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventTicketingService
{
    public function __construct(
        private readonly ZengaPayService $zengaPayService,
        private readonly EventFeeCalculatorService $eventFeeCalculatorService,
        private readonly EventPayoutLedgerService $eventPayoutLedgerService,
        private readonly EventDiscountCodeService $eventDiscountCodeService,
    ) {}

    public function purchase(?User $user, array $validated): array
    {
        return DB::transaction(function () use ($user, $validated) {
            $event = Event::findOrFail($validated['event_id']);
            $purchaser = $this->resolvePurchaser($user, $validated);
            $selections = collect($validated['tickets'] ?? [])->map(fn (array $selection) => [
                'ticket_tier_id' => (int) $selection['ticket_tier_id'],
                'quantity' => (int) $selection['quantity'],
            ])->values();
            $paymentMethod = $validated['payment_method'];
            $lockedTickets = EventTicket::where('event_id', $event->id)
                ->whereIn('id', $selections->pluck('ticket_tier_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedTickets->count() !== $selections->count()) {
                $this->failPurchase('One or more selected ticket tiers are not available for this event');
            }

            foreach ($selections as $selection) {
                /** @var EventTicket $ticket */
                $ticket = $lockedTickets->get($selection['ticket_tier_id']);
                $quantity = $selection['quantity'];

                if (! $ticket->isOnSale()) {
                    $this->failPurchase("{$ticket->name} is not currently available");
                }

                if (! $ticket->isValidOrderQuantity($quantity)) {
                    $this->failPurchase([
                        'message' => "{$ticket->name} quantity must be between {$ticket->min_per_order} and {$ticket->max_per_order}",
                    ]);
                }

                if (! $ticket->canPurchase($quantity)) {
                    $available = $ticket->quantity_available ?? 0;
                    $this->failPurchase("Only {$available} {$ticket->name} tickets remaining");
                }
            }

            $discountCode = $this->resolveDiscountCode($event, $lockedTickets, $selections, $validated['discount_code'] ?? null);
            $feeBreakdown = $this->eventFeeCalculatorService->calculateForSelections($lockedTickets, $selections->all(), $discountCode);
            $grandTotal = (float) $feeBreakdown['total_amount'];
            $serviceFee = (float) $feeBreakdown['total_fee_amount'];
            $totalCredits = (int) round((float) $feeBreakdown['total_credits']);
            $orderId = 'ORD-'.strtoupper(Str::random(10));
            $attribution = $this->sanitizeAttribution($validated['attribution'] ?? null);
            $attendeeAssignments = $this->mapAttendeeAssignments($validated['attendee_assignments'] ?? []);

            $this->assertFundsAvailable($purchaser, $paymentMethod, $grandTotal, $totalCredits, $lockedTickets, $selections, $user === null);
            $this->assertPaymentChannelRequirements($paymentMethod, $validated['phone'] ?? null);

            $payment = $this->createPaymentRecord(
                user: $purchaser,
                event: $event,
                tickets: $lockedTickets,
                paymentMethod: $paymentMethod,
                orderId: $orderId,
                grandTotal: $grandTotal,
                selections: $selections->all(),
                phoneNumber: $validated['phone'] ?? null,
                feeBreakdown: $feeBreakdown,
                attribution: $attribution,
                discountCode: $discountCode,
                guestCheckout: $user === null,
                guestContact: [
                    'name' => $validated['holder_name'] ?? null,
                    'email' => $validated['holder_email'] ?? null,
                    'phone' => $validated['holder_phone'] ?? $validated['phone'] ?? null,
                ],
            );
            $this->eventPayoutLedgerService->syncPendingForPayment($payment);
            if ($discountCode) {
                $this->eventDiscountCodeService->incrementUsage($discountCode);
            }

            foreach ($selections as $selection) {
                $lockedTickets->get($selection['ticket_tier_id'])?->reserve($selection['quantity']);
            }

            $issuedTickets = [];
            $instantSettlement = in_array($paymentMethod, ['wallet', 'credits'], true);

            foreach ($feeBreakdown['items'] ?? [] as $lineItem) {
                /** @var EventTicket $ticket */
                $ticket = $lockedTickets->get($lineItem['ticket_tier_id']);
                $pricePerTicket = (float) $lineItem['unit_price_ugx'];
                $creditsPerTicket = (int) round((float) $lineItem['unit_price_credits']);
                $assignedAttendees = $attendeeAssignments[$ticket->id] ?? [];

                for ($i = 0; $i < (int) $lineItem['quantity']; $i++) {
                    $assignedAttendee = $assignedAttendees[$i] ?? [];
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
                        'user_id' => $purchaser->id,
                        'attendee_name' => $assignedAttendee['name']
                            ?? $validated['holder_name']
                            ?? $purchaser->full_name
                            ?? $purchaser->display_name
                            ?? $purchaser->username
                            ?? $purchaser->name,
                        'attendee_email' => $assignedAttendee['email']
                            ?? $validated['holder_email']
                            ?? data_get($payment->metadata, 'guest_checkout.contact.email')
                            ?? $purchaser->email,
                        'attendee_phone' => $assignedAttendee['phone']
                            ?? $validated['holder_phone']
                            ?? data_get($payment->metadata, 'guest_checkout.contact.phone')
                            ?? $purchaser->phone,
                        'price_paid_ugx' => $paymentMethod === 'credits' ? 0 : $pricePerTicket,
                        'price_paid_credits' => $paymentMethod === 'credits' ? $creditsPerTicket : 0,
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
                            'discount_amount' => (float) ($lineItem['discount_amount'] ?? 0),
                            'payment_id' => $payment->id,
                            'fee_breakdown' => $feeBreakdown,
                            'line_item_fee_breakdown' => $lineItem,
                            'attribution' => $attribution,
                            'assigned_attendee' => [
                                'name' => $assignedAttendee['name'] ?? null,
                                'email' => $assignedAttendee['email'] ?? null,
                                'phone' => $assignedAttendee['phone'] ?? null,
                            ],
                            'guest_checkout' => $user === null,
                        ],
                    ]);

                    $issuedTickets[] = [
                        'id' => $attendee->id,
                        'ticket_number' => $ticketNumber,
                        'qr_code' => $attendee->qr_code,
                        'status' => $attendee->status,
                        'tier' => $ticket->name,
                        'price' => $pricePerTicket,
                        'holder_name' => $attendee->attendee_name,
                        'holder_email' => $attendee->attendee_email,
                    ];
                }
            }

            if ($instantSettlement) {
                $this->settleInstantPayment($purchaser, $paymentMethod, $grandTotal, $totalCredits);
                foreach ($selections as $selection) {
                    $lockedTickets->get($selection['ticket_tier_id'])?->sell($selection['quantity']);
                }
                $payment->markAsCompleted([
                    'provider_reference' => $payment->payment_reference,
                ]);
            } elseif (in_array($paymentMethod, ['mtn_momo', 'airtel_money'], true)) {
                $this->initiateMobileMoneyCollection($payment, $validated['phone'] ?? null);
            }

            return [
                'data' => [
                    'order_id' => $orderId,
                    'tickets' => $issuedTickets,
                    'total_amount' => $grandTotal,
                    'base_amount' => (float) $feeBreakdown['base_amount'],
                    'service_fee' => $serviceFee,
                    'fee_breakdown' => $feeBreakdown,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $payment->payment_reference,
                    'status' => $instantSettlement ? 'completed' : 'pending_payment',
                    'line_items' => $feeBreakdown['items'] ?? [],
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

            $this->eventDiscountCodeService->decrementUsageByPaymentMetadata($payment->metadata ?? []);
        });
    }

    private function assertFundsAvailable(
        User $user,
        string $paymentMethod,
        float $grandTotal,
        int $totalCredits,
        Collection $tickets,
        Collection $selections,
        bool $isGuestCheckout = false,
    ): void {
        if ($isGuestCheckout && in_array($paymentMethod, ['wallet', 'credits'], true)) {
            $this->failPurchase('Sign in to pay with your Tesotunes wallet or credits');
        }

        if ($paymentMethod === 'credits') {
            foreach ($selections as $selection) {
                /** @var EventTicket|null $ticket */
                $ticket = $tickets->get($selection['ticket_tier_id']);
                if (! $ticket || (int) $ticket->price_credits <= 0) {
                    $ticketName = $ticket?->name ?? 'Selected';
                    $this->failPurchase("{$ticketName} ticket does not support credit payment");
                }
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
        Collection $tickets,
        string $paymentMethod,
        string $orderId,
        float $grandTotal,
        array $selections,
        ?string $phoneNumber,
        array $feeBreakdown,
        array $attribution,
        ?EventDiscountCode $discountCode,
        bool $guestCheckout = false,
        array $guestContact = [],
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
            'email' => $guestCheckout ? ($guestContact['email'] ?? null) : $user->email,
            'description' => "Ticket purchase for event #{$event->id} - Order {$orderId}",
            'metadata' => [
                'order_id' => $orderId,
                'event_id' => $event->id,
                'ticket_id' => data_get($selections, '0.ticket_tier_id'),
                'ticket_ids' => collect($selections)->pluck('ticket_tier_id')->values()->all(),
                'quantity' => (int) collect($selections)->sum('quantity'),
                'line_items' => $feeBreakdown['items'] ?? [],
                'payment_channel' => $paymentMethod,
                'fee_breakdown' => $feeBreakdown,
                'attribution' => $attribution,
                'guest_checkout' => $guestCheckout ? [
                    'enabled' => true,
                    'contact' => [
                        'name' => $guestContact['name'] ?? null,
                        'email' => $guestContact['email'] ?? null,
                        'phone' => $guestContact['phone'] ?? null,
                    ],
                ] : null,
                'discount_code' => $discountCode ? [
                    'id' => $discountCode->id,
                    'code' => $discountCode->code,
                    'name' => $discountCode->name,
                    'discount_type' => $discountCode->discount_type,
                    'discount_value' => (float) $discountCode->discount_value,
                    'discount_amount' => (float) ($feeBreakdown['discount_amount'] ?? 0),
                ] : null,
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
            $transaction = $user->spendCredits(
                $totalCredits,
                'event_ticket_purchase',
                'Purchased event tickets with credits',
                ['total_credits' => $totalCredits]
            );

            if (! $transaction) {
                $this->failPurchase('Insufficient credits');
            }
        }
    }

    private function initiateMobileMoneyCollection(Payment $payment, ?string $phoneNumber): void
    {
        if (app()->environment('testing')) {
            $payment->markAsProcessing();

            return;
        }

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

    private function sanitizeAttribution(mixed $attribution): array
    {
        if (! is_array($attribution)) {
            return [];
        }

        $allowedKeys = [
            'source',
            'channel',
            'campaign_code',
            'referral_code',
            'promoter_code',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'landing_page',
        ];

        return collect($attribution)
            ->only($allowedKeys)
            ->map(function ($value) {
                if ($value === null) {
                    return null;
                }

                return is_scalar($value) ? trim((string) $value) : null;
            })
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    private function mapAttendeeAssignments(array $assignments): array
    {
        return collect($assignments)
            ->filter(fn ($assignment) => is_array($assignment) && isset($assignment['ticket_tier_id']))
            ->mapWithKeys(fn (array $assignment) => [
                (int) $assignment['ticket_tier_id'] => array_values(array_filter(array_map(function ($attendee) {
                    if (! is_array($attendee)) {
                        return null;
                    }

                    $name = trim((string) ($attendee['name'] ?? ''));
                    $email = trim((string) ($attendee['email'] ?? ''));
                    $phone = trim((string) ($attendee['phone'] ?? ''));

                    if ($name === '' && $email === '' && $phone === '') {
                        return null;
                    }

                    return [
                        'name' => $name !== '' ? $name : null,
                        'email' => $email !== '' ? $email : null,
                        'phone' => $phone !== '' ? $phone : null,
                    ];
                }, $assignment['attendees'] ?? []))),
            ])
            ->all();
    }

    private function resolveDiscountCode(Event $event, Collection $lockedTickets, Collection $selections, ?string $code): ?EventDiscountCode
    {
        if (blank($code)) {
            return null;
        }

        return $this->eventDiscountCodeService
            ->validateForQuote($event, $lockedTickets->values(), $selections->all(), $code)['discount_code'];
    }

    private function resolvePurchaser(?User $user, array $validated): User
    {
        if ($user instanceof User) {
            return $user;
        }

        $holderName = trim((string) ($validated['holder_name'] ?? ''));
        $holderEmail = trim((string) ($validated['holder_email'] ?? ''));
        $holderPhone = trim((string) ($validated['holder_phone'] ?? ($validated['phone'] ?? '')));

        if ($holderName === '' || $holderEmail === '') {
            $this->failPurchase('Guest checkout requires your name and email address');
        }

        $guestUser = new User([
            'name' => $holderName,
            'display_name' => $holderName,
            'full_name' => $holderName,
            'username' => 'guest_'.Str::lower(Str::random(10)),
            'email' => 'guest+'.Str::lower(Str::random(18)).'@tesotunes.local',
            'password' => Hash::make(Str::random(40)),
            'phone' => $holderPhone !== '' ? $holderPhone : null,
            'settings' => [
                'guest_checkout' => true,
                'guest_contact_email' => $holderEmail,
            ],
        ]);

        $guestUser->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return $guestUser;
    }
}
