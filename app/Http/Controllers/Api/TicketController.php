<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Payment;
use App\Services\Payment\ZengaPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    /**
     * POST /api/tickets/purchase
     */
    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'ticket_tier_id' => 'required|integer|exists:event_ticket_types,id',
            'quantity' => 'required|integer|min:1|max:10',
            'payment_method' => 'required|in:wallet,mtn_momo,airtel_money,card,credits',
            'phone' => 'nullable|string',
            'holder_name' => 'nullable|string|max:150',
            'holder_email' => 'nullable|email|max:150',
            'holder_phone' => 'nullable|string|max:20',
        ]);

        $user = auth()->user();
        $event = Event::findOrFail($validated['event_id']);
        $ticketType = EventTicket::where('event_id', $event->id)
            ->findOrFail($validated['ticket_tier_id']);

        // Validate ticket is on sale
        if (! $ticketType->isOnSale()) {
            return response()->json(['message' => 'This ticket type is not currently available'], 422);
        }

        // Validate quantity
        if (! $ticketType->isValidOrderQuantity($validated['quantity'])) {
            return response()->json([
                'message' => "Order quantity must be between {$ticketType->min_per_order} and {$ticketType->max_per_order}",
            ], 422);
        }

        // Check availability
        if ($ticketType->quantity_total !== null) {
            $available = $ticketType->quantity_total - $ticketType->quantity_sold - $ticketType->quantity_reserved;
            if ($validated['quantity'] > $available) {
                return response()->json(['message' => "Only {$available} tickets remaining"], 422);
            }
        }

        $pricePerTicket = $ticketType->price_ugx;
        $totalAmount = $pricePerTicket * $validated['quantity'];
        $serviceFee = round($totalAmount * 0.05, 2); // 5% platform fee
        $grandTotal = $totalAmount + $serviceFee;
        $paymentMethod = $validated['payment_method'];

        // Handle credits payment
        if ($paymentMethod === 'credits') {
            $pricePerTicketCredits = $ticketType->price_credits;
            if ($pricePerTicketCredits <= 0) {
                return response()->json(['message' => 'This ticket does not support credit payment'], 422);
            }
            $totalCredits = $pricePerTicketCredits * $validated['quantity'];
            if ($user->credits < $totalCredits) {
                return response()->json(['message' => 'Insufficient credits'], 422);
            }
            $user->decrement('credits', $totalCredits);
        }

        // Handle wallet payment
        if ($paymentMethod === 'wallet') {
            if ($user->ugx_balance < $grandTotal) {
                return response()->json(['message' => 'Insufficient wallet balance'], 422);
            }
            $user->decrement('ugx_balance', $grandTotal);
        }

        // Reserve tickets
        $ticketType->reserve($validated['quantity']);

        // Generate tickets
        $orderId = 'ORD-'.strtoupper(Str::random(10));
        $tickets = [];

        for ($i = 0; $i < $validated['quantity']; $i++) {
            $ticketNumber = 'TKT-'.strtoupper(Str::random(8));
            $qrData = json_encode([
                'ticket' => $ticketNumber,
                'event' => $event->id,
                'type' => $ticketType->name,
            ]);

            $registration = EventAttendee::create([
                'uuid' => (string) Str::uuid(),
                'confirmation_code' => $ticketNumber,
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'user_id' => $user->id,
                'attendee_name' => $validated['holder_name'] ?? $user->full_name ?? $user->display_name ?? $user->username,
                'attendee_email' => $validated['holder_email'] ?? $user->email,
                'attendee_phone' => $validated['holder_phone'] ?? $user->phone,
                'price_paid_ugx' => $paymentMethod === 'credits' ? 0 : $pricePerTicket,
                'price_paid_credits' => $paymentMethod === 'credits' ? ($ticketType->price_credits) : 0,
                'payment_method' => $paymentMethod === 'credits' ? 'credits' : 'ugx',
                'status' => in_array($paymentMethod, ['wallet', 'credits']) ? 'confirmed' : 'pending',
                'confirmed_at' => in_array($paymentMethod, ['wallet', 'credits']) ? now() : null,
                'qr_code' => base64_encode($qrData),
            ]);

            $tickets[] = [
                'id' => $registration->id,
                'ticket_number' => $ticketNumber,
                'qr_code' => $registration->qr_code,
                'status' => $registration->status,
                'tier' => $ticketType->name,
                'price' => (float) $pricePerTicket,
                'holder_name' => $registration->attendee_name,
            ];
        }

        // Mark as sold if wallet/credits (instant payment)
        if (in_array($paymentMethod, ['wallet', 'credits'])) {
            $ticketType->sell($validated['quantity']);
        }

        // For mobile money, initiate payment via ZengaPay (external webhook will confirm)
        $paymentReference = null;
        if (in_array($paymentMethod, ['mtn_momo', 'airtel_money'])) {
            $paymentReference = 'PAY-'.strtoupper(Str::random(12));

            $payment = Payment::create([
                'user_id' => auth()->id(),
                'amount' => $grandTotal,
                'currency' => 'UGX',
                'payment_reference' => $paymentReference,
                'payment_method' => $paymentMethod,
                'payment_type' => 'ticket_purchase',
                'provider' => 'zengapay',
                'phone_number' => $validated['phone_number'] ?? null,
                'status' => 'pending',
                'description' => "Ticket purchase for event #{$event->id} - Order {$orderId}",
                'metadata' => [
                    'order_id' => $orderId,
                    'event_id' => $event->id,
                    'ticket_type_id' => $ticketType->id,
                    'quantity' => $validated['quantity'],
                ],
            ]);

            try {
                $zengaPay = app(ZengaPayService::class);
                $zengaPay->processPayment($payment, [
                    'phone_number' => $validated['phone_number'] ?? null,
                ]);
            } catch (\Exception $e) {
                // Payment initiation failed but tickets are reserved — payment can be retried
                \Illuminate\Support\Facades\Log::warning('ZengaPay ticket payment initiation failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'order_id' => $orderId,
                'tickets' => $tickets,
                'total_amount' => $grandTotal,
                'service_fee' => $serviceFee,
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'status' => in_array($paymentMethod, ['wallet', 'credits']) ? 'completed' : 'pending_payment',
            ],
            'message' => 'Tickets purchased successfully',
        ], 201);
    }

    /**
     * GET /api/tickets/my — user's tickets
     */
    public function myTickets(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $user = auth()->user();

        $registrations = EventAttendee::with(['event.organizer', 'event.location'])
            ->where('user_id', $user->id)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $registrations->getCollection()->map(function ($r) {
            return [
                'id' => $r->id,
                'ticket_number' => $r->confirmation_code,
                'qr_code' => $r->qr_code,
                'status' => $r->status,
                'holder_name' => $r->attendee_name,
                'holder_email' => $r->attendee_email,
                'price_paid' => (float) $r->price_paid_ugx,
                'price_paid_credits' => (float) $r->price_paid_credits,
                'payment_method' => $r->payment_method,
                'checked_in_at' => $r->checked_in_at,
                'event' => $r->event ? [
                    'id' => $r->event->id,
                    'title' => $r->event->title,
                    'starts_at' => $r->event->starts_at?->toIso8601String(),
                    'artwork' => $r->event->artwork ? url('storage/'.$r->event->artwork) : null,
                    'venue_name' => $r->event->venue_name,
                    'city' => $r->event->city,
                ] : null,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $registrations->currentPage(),
                'last_page' => $registrations->lastPage(),
                'per_page' => $registrations->perPage(),
                'total' => $registrations->total(),
            ],
        ]);
    }

    /**
     * GET /api/tickets/{id}
     */
    public function show(int $id)
    {
        $user = auth()->user();
        $registration = EventAttendee::with(['event.organizer', 'event.location'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'ticket_number' => $registration->confirmation_code,
                'qr_code' => $registration->qr_code,
                'status' => $registration->status,
                'holder_name' => $registration->attendee_name,
                'holder_email' => $registration->attendee_email,
                'holder_phone' => $registration->attendee_phone,
                'price_paid' => (float) $registration->price_paid_ugx,
                'price_paid_credits' => (float) $registration->price_paid_credits,
                'payment_method' => $registration->payment_method,
                'checked_in_at' => $registration->checked_in_at,
                'confirmed_at' => $registration->confirmed_at,
                'event' => $registration->event ? [
                    'id' => $registration->event->id,
                    'title' => $registration->event->title,
                    'starts_at' => $registration->event->starts_at?->toIso8601String(),
                    'ends_at' => $registration->event->ends_at?->toIso8601String(),
                    'venue_name' => $registration->event->venue_name,
                    'city' => $registration->event->city,
                    'artwork' => $registration->event->artwork ? url('storage/'.$registration->event->artwork) : null,
                ] : null,
                'created_at' => $registration->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/tickets/validate/{ticketNumber}
     */
    public function validate_(string $ticketNumber)
    {
        $registration = EventAttendee::with(['event'])
            ->where('confirmation_code', $ticketNumber)
            ->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid ticket number', 'valid' => false], 404);
        }

        return response()->json([
            'valid' => true,
            'data' => [
                'id' => $registration->id,
                'ticket_number' => $registration->confirmation_code,
                'status' => $registration->status,
                'holder_name' => $registration->attendee_name,
                'checked_in_at' => $registration->checked_in_at,
                'event' => [
                    'id' => $registration->event->id,
                    'title' => $registration->event->title,
                    'starts_at' => $registration->event->starts_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/tickets/check-in
     */
    public function checkIn(Request $request)
    {
        $validated = $request->validate([
            'ticket_number' => 'required|string',
        ]);

        $registration = EventAttendee::where('confirmation_code', $validated['ticket_number'])->first();

        if (! $registration) {
            return response()->json(['message' => 'Invalid ticket number'], 404);
        }

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'This ticket has been cancelled'], 422);
        }

        if ($registration->checked_in_at) {
            return response()->json([
                'message' => 'Ticket already checked in at '.$registration->checked_in_at->format('M j, Y g:i A'),
                'data' => [
                    'ticket_number' => $registration->confirmation_code,
                    'checked_in_at' => $registration->checked_in_at->toIso8601String(),
                    'holder_name' => $registration->attendee_name,
                ],
            ], 422);
        }

        $registration->update([
            'checked_in_at' => now(),
            'status' => 'attended',
        ]);

        // Award loyalty points if event has a loyalty card
        $event = $registration->event;
        if ($event && $event->loyalty_points_per_checkin > 0 && $registration->user_id) {
            // Award points via loyalty service
            try {
                $pointsService = app(\App\Services\Loyalty\LoyaltyPointsService::class);
                $pointsService->awardPoints(
                    user: \App\Models\User::find($registration->user_id),
                    basePoints: $event->loyalty_points_per_checkin,
                    source: 'event_checkin',
                    sourceId: $event->id,
                    sourceType: get_class($event),
                    description: "Check-in at: {$event->title}",
                );
            } catch (\Exception $e) {
                // Log but don't fail the check-in
                \Log::warning("Failed to award loyalty points for event check-in: {$e->getMessage()}");
            }
        }

        return response()->json([
            'message' => 'Ticket checked in successfully',
            'data' => [
                'ticket_number' => $registration->confirmation_code,
                'holder_name' => $registration->attendee_name,
                'checked_in_at' => $registration->checked_in_at->toIso8601String(),
                'event' => $event ? $event->title : null,
                'loyalty_points_earned' => ($event && $event->loyalty_points_per_checkin > 0) ? $event->loyalty_points_per_checkin : 0,
            ],
        ]);
    }
}
