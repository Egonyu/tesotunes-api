<?php

namespace App\Observers;

use App\Models\Event;
use App\Services\ActivityService;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        // Log activity when event is published
        if ($event->status === 'published' && $event->user) {
            ActivityService::log(
                actor: $event->user,
                action: 'created_event',
                subject: $event,
                metadata: [
                    'event_title' => $event->title,
                    'venue' => $event->venue,
                    'start_date' => $event->starts_at?->format('Y-m-d H:i'),
                    'category' => $event->category,
                ]
            );
        }
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        // Log activity when event status changes to published
        if ($event->isDirty('status') && $event->status === 'published' && $event->user) {
            ActivityService::log(
                actor: $event->user,
                action: 'created_event',
                subject: $event,
                metadata: [
                    'event_title' => $event->title,
                    'venue' => $event->venue,
                    'start_date' => $event->starts_at?->format('Y-m-d H:i'),
                    'category' => $event->category,
                ]
            );
        }
    }

    /**
     * Handle the Event "deleted" event.
     */
    public function deleted(Event $event): void
    {
        //
    }

    /**
     * Handle the Event "restored" event.
     */
    public function restored(Event $event): void
    {
        //
    }

    /**
     * Handle the Event "force deleted" event.
     */
    public function forceDeleted(Event $event): void
    {
        //
    }
}
