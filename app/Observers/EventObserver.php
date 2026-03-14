<?php

namespace App\Observers;

use App\Models\Event;
use App\Services\ActivityService;
use App\Services\FeedItemService;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        // Log activity when event is published
        $organizer = $event->organizer ?? $event->user;
        if ($event->status === 'published' && $organizer) {
            ActivityService::log(
                actor: $organizer,
                action: 'created_event',
                subject: $event,
                metadata: [
                    'event_title' => $event->title,
                    'venue' => $event->venue_name,
                    'start_date' => $event->starts_at?->format('Y-m-d H:i'),
                    'category' => $event->category,
                ]
            );

            FeedItemService::create([
                'type' => 'event_created',
                'module' => 'events',
                'title' => 'New event: '.$event->title,
                'body' => $event->description ? substr($event->description, 0, 200) : null,
                'actor_id' => $organizer->id,
                'actor_type' => 'user',
                'actor_name' => $organizer->name,
                'actor_avatar_url' => $organizer->avatar_url,
                'subject_type' => Event::class,
                'subject_id' => $event->id,
                'media_type' => 'image',
                'media_url' => $event->banner ? \App\Helpers\StorageHelper::url($event->banner) : \App\Helpers\StorageHelper::url($event->artwork),
                'actions' => [
                    ['type' => 'view', 'label' => 'View Event', 'url' => "/events/{$event->id}"],
                    ['type' => 'register', 'label' => 'Interested', 'url' => "/events/{$event->id}"],
                ],
                'extras' => [
                    'event_id' => $event->id,
                    'venue' => $event->venue_name,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ticket_price' => $event->ticket_price,
                    'category' => $event->category,
                ],
            ]);
        }
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        // Log activity when event status changes to published
        $organizer = $event->organizer ?? $event->user;
        if ($event->isDirty('status') && $event->status === 'published' && $organizer) {
            ActivityService::log(
                actor: $organizer,
                action: 'created_event',
                subject: $event,
                metadata: [
                    'event_title' => $event->title,
                    'venue' => $event->venue_name,
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
