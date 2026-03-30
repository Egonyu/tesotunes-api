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

class EventTicketConfirmationNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, ChecksNotificationPreferences, Queueable;

    public function __construct(
        protected object $attendee,
        protected object $ticket,
        protected object $event
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            ['mail', AppNotificationChannel::class, ExpoPushChannel::class],
            'music'
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $eventName = $this->event->title ?? $this->event->name ?? 'Event';
        $ticketType = $this->ticket->name ?? $this->ticket->ticket_type ?? 'General';
        $eventDate = $this->event->starts_at?->format('M j, Y g:i A') ?? 'TBD';
        $displayName = $notifiable->display_name
            ?? $notifiable->name
            ?? $this->attendee->attendee_name
            ?? 'there';

        return (new MailMessage)
            ->subject("Ticket Confirmed — {$eventName}")
            ->greeting("Hey {$displayName}!")
            ->line("Your ticket for **{$eventName}** has been confirmed!")
            ->line("**Ticket Type**: {$ticketType}")
            ->line("**Event Date**: {$eventDate}")
            ->line("**Ticket Reference**: {$this->attendee->confirmation_code}")
            ->action('View My Tickets', $this->frontendUrl('/tickets'))
            ->line('See you there!');
    }

    public function toArray(object $notifiable): array
    {
        $eventName = $this->event->title ?? $this->event->name ?? 'Event';

        return [
            'type' => 'ticket_confirmation',
            'module' => 'events',
            'event_id' => $this->event->id,
            'event_name' => $eventName,
            'ticket_code' => $this->attendee->confirmation_code ?? null,
            'title' => 'Ticket Confirmed',
            'message' => "Your ticket for \"{$eventName}\" has been confirmed!",
            'icon' => 'ticket',
            'color' => 'green',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        $eventName = $this->event->title ?? $this->event->name ?? 'Event';

        return [
            'title' => 'Ticket Confirmed!',
            'body' => "You're going to {$eventName}!",
            'data' => [
                'type' => 'ticket_confirmation',
                'eventId' => $this->event->id,
                'screen' => 'EventDetail',
                'params' => ['eventId' => $this->event->id],
            ],
        ];
    }
}
