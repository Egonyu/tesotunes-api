<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Notifications\EventCancellationNotification;
use App\Notifications\EventReminderNotification;
use Illuminate\Support\Facades\Cache;

class EventNotificationService
{
    public function sendUpcomingEventReminders(int $hoursUntil = 24): int
    {
        $windowStart = now()->addHours($hoursUntil)->startOfHour();
        $windowEnd = (clone $windowStart)->addHour();

        $events = Event::with([
            'attendees.user',
            'attendees.ticket',
        ])
            ->where('status', 'published')
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            foreach ($event->attendees as $attendee) {
                if (! $attendee->user || $attendee->status !== 'confirmed') {
                    continue;
                }

                $cacheKey = sprintf('events:reminder:%d:%d:%d', $event->id, $attendee->id, $hoursUntil);
                if (Cache::has($cacheKey)) {
                    continue;
                }

                $attendee->user->notify(new EventReminderNotification($event, $attendee, $hoursUntil));
                Cache::put($cacheKey, true, now()->addDays(7));
                $sent++;
            }
        }

        return $sent;
    }

    public function sendCancellationNotifications(Event $event, ?string $reason = null): int
    {
        $event->loadMissing(['attendees.user']);

        $sent = 0;

        foreach ($event->attendees as $attendee) {
            if (! $attendee->user || in_array($attendee->status, ['cancelled', 'no_show'], true)) {
                continue;
            }

            $attendee->user->notify(new EventCancellationNotification($event, $attendee, $reason));
            $sent++;
        }

        return $sent;
    }
}
