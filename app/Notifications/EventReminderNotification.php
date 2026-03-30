<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected object $event,
        protected object $attendee,
        protected int $hoursUntil = 24
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            [AppNotificationChannel::class, ExpoPushChannel::class, 'mail'],
            'music'
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $eventName = $this->event->title ?? $this->event->name ?? 'Event';
        $venue = $this->event->venue_name ?? $this->event->location?->name ?? 'TBD';
        $eventDate = $this->event->starts_at?->format('M j, Y g:i A') ?? 'TBD';

        return (new MailMessage)
            ->subject("Reminder: {$eventName} starts in {$this->hoursUntil} hours!")
            ->greeting("Hey {$notifiable->display_name}!")
            ->line("Just a reminder — **{$eventName}** starts in **{$this->hoursUntil} hours**!")
            ->line("**Venue**: {$venue}")
            ->line("**Date**: {$eventDate}")
            ->line("**Your Ticket**: {$this->attendee->confirmation_code}")
            ->action('View Event Details', $this->frontendUrl("/events/{$this->event->id}"))
            ->line('See you there!');
    }

    public function toArray(object $notifiable): array
    {
        $eventName = $this->event->title ?? $this->event->name ?? 'Event';

        return [
            'type' => 'event_reminder',
            'module' => 'events',
            'event_id' => $this->event->id,
            'event_name' => $eventName,
            'hours_until' => $this->hoursUntil,
            'title' => 'Event Reminder',
            'message' => "{$eventName} starts in {$this->hoursUntil} hours!",
            'icon' => 'bell',
            'color' => 'orange',
            'priority' => 'high',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $eventName = $this->event->title ?? $this->event->name ?? 'Event';

        return [
            'title' => 'Event Reminder',
            'body' => "{$eventName} starts in {$this->hoursUntil} hours!",
            'data' => [
                'type' => 'event_reminder',
                'eventId' => $this->event->id,
                'screen' => 'EventDetail',
                'params' => ['eventId' => $this->event->id],
            ],
            'options' => [
                'priority' => 'high',
            ],
        ];
    }
}
