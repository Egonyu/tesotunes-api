<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Events\Podcast\NewEpisodePublished;
use App\Events\Podcast\NewPodcastPublished;
use App\Events\TicketPurchased;
use App\Notifications\EventTicketConfirmationNotification;
use App\Notifications\NewEpisodePublishedNotification;
use App\Notifications\NewPodcastPublishedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendEventNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public function handleNewEpisodePublished(NewEpisodePublished $event): void
    {
        try {
            $episode = $event->episode;
            $podcast = $episode->podcast;

            if (! $podcast) {
                return;
            }

            $subscribers = $podcast->subscribers()->get();

            if ($subscribers->isNotEmpty()) {
                Notification::send($subscribers, new NewEpisodePublishedNotification($episode));
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send new episode notification: {$e->getMessage()}");
        }
    }

    public function handleNewPodcastPublished(NewPodcastPublished $event): void
    {
        try {
            $podcast = $event->podcast;
            $creator = $podcast->creator;

            if (! $creator) {
                return;
            }

            // Notify creator's followers about the new podcast
            $followers = $creator->followers()
                ->with('follower')
                ->get()
                ->pluck('follower')
                ->filter();

            if ($followers->isNotEmpty()) {
                Notification::send($followers, new NewPodcastPublishedNotification($podcast));
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send new podcast notification: {$e->getMessage()}");
        }
    }

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

            $user->notify(new EventTicketConfirmationNotification(
                $attendee,
                $event->ticket,
                $event->event
            ));
        } catch (\Throwable $e) {
            Log::warning("Failed to send ticket confirmation notification: {$e->getMessage()}");
        }
    }
}
