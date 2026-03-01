<?php

namespace App\Listeners;

use App\Events\AttendeeCheckedIn;
use App\Events\TicketPurchased;
use App\Services\Loyalty\LoyaltyPointsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class AwardEventLoyaltyPoints implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected LoyaltyPointsService $pointsService,
    ) {}

    /**
     * Award points when a user purchases a ticket.
     */
    public function handleTicketPurchased(TicketPurchased $event): void
    {
        try {
            $attendee = $event->attendee;
            if (! $attendee || ! $attendee->user_id) {
                return;
            }

            $user = \App\Models\User::find($attendee->user_id);
            if (! $user) {
                return;
            }

            $basePoints = config('loyalty.points_earning.purchase_per_100_ugx', 1);
            $price = $event->ticket->price_ugx ?? $event->ticket->price ?? 0;
            $points = (int) floor($price / 100) * $basePoints;

            if ($points > 0) {
                $this->pointsService->awardPoints(
                    $user,
                    $points,
                    'purchase',
                    $attendee->id,
                    get_class($attendee),
                    "Ticket purchase for: {$event->event->title}"
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to award loyalty points for ticket purchase: {$e->getMessage()}");
        }
    }

    /**
     * Award points when a user checks in at an event.
     */
    public function handleAttendeeCheckedIn(AttendeeCheckedIn $event): void
    {
        try {
            $attendee = $event->attendee;
            if (! $attendee || ! $attendee->user_id) {
                return;
            }

            $user = \App\Models\User::find($attendee->user_id);
            if (! $user) {
                return;
            }

            $basePoints = config('loyalty.points_earning.event_attendance', 10);

            $this->pointsService->awardPoints(
                $user,
                $basePoints,
                'event_attendance',
                $attendee->id,
                get_class($attendee),
                "Checked in at: {$event->event->title}"
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to award loyalty points for event check-in: {$e->getMessage()}");
        }
    }
}
