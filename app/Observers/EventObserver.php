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

            FeedItemService::create([
                'type'            => 'event_created',
                'module'          => 'events',
                'title'           => 'New event: ' . $event->title,
                'body'            => $event->description ? substr($event->description, 0, 200) : null,
                'actor_id'        => $event->user->id,
                'actor_type'      => 'user',
                'actor_name'      => $event->user->name,
                'actor_avatar_url'=> $event->user->avatar_url,
                'subject_type'    => Event::class,
                'subject_id'      => $event->id,
                'media_type'      => 'image',
                'media_url'       => $event->banner_url ?? $event->image_url,
                'actions'         => [
                    ['type' => 'view', 'label' => 'View Event', 'url' => "/events/{$event->slug}"],
                    ['type' => 'register', 'label' => 'Interested', 'url' => "/events/{$event->slug}"],
                ],
                'extras'          => [
                    'event_id'     => $event->id,
                    'venue'        => $event->venue,
                    'starts_at'    => $event->starts_at?->toIso8601String(),
                    'ticket_price' => $event->ticket_price,
                    'category'     => $event->category,
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
