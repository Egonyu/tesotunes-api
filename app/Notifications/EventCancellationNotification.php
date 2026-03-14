<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventCancellationNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected object $event,
        protected object $attendee,
        protected ?string $reason = null,
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
        $eventName = $this->event->title ?? 'Event';
        $eventDate = $this->event->starts_at?->format('M j, Y g:i A') ?? 'TBD';

        $mail = (new MailMessage)
            ->subject("Cancelled: {$eventName}")
            ->greeting("Hey {$notifiable->display_name}!")
            ->line("Your event booking for **{$eventName}** has been cancelled.")
            ->line("**Date**: {$eventDate}")
            ->line("**Ticket Reference**: ".($this->attendee->confirmation_code ?? 'N/A'))
            ->action('View Event', url("/events/{$this->event->id}"));

        if ($this->reason) {
            $mail->line("Reason: {$this->reason}");
        }

        return $mail->line('We will share any follow-up updates in your notifications.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'event_cancelled',
            'module' => 'events',
            'event_id' => $this->event->id,
            'event_name' => $this->event->title ?? 'Event',
            'ticket_code' => $this->attendee->confirmation_code ?? null,
            'title' => 'Event Cancelled',
            'message' => ($this->event->title ?? 'Your event').' has been cancelled.',
            'reason' => $this->reason,
            'icon' => 'calendar-x',
            'color' => 'red',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'Event Cancelled',
            'body' => ($this->event->title ?? 'Your event').' has been cancelled.',
            'data' => [
                'type' => 'event_cancelled',
                'eventId' => $this->event->id,
                'screen' => 'EventDetail',
                'params' => ['eventId' => $this->event->id],
            ],
        ];
    }
}
