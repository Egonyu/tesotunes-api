<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Models\Song;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to admin/super_admin users when an artist uploads a song
 * that requires moderation (status = pending).
 */
class AdminSongPendingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Song $song,
        protected User $artist
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New Song Pending Review: {$this->song->title}")
            ->greeting("Hello {$notifiable->display_name}!")
            ->line("**{$this->artist->name}** uploaded a new song that needs your review:")
            ->line("**Song:** {$this->song->title}")
            ->action('Review Song', url("/admin/songs/{$this->song->id}"))
            ->line('Please review and approve or reject the submission.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'song_pending_review',
            'module' => 'music',
            'song_id' => $this->song->id,
            'song_title' => $this->song->title,
            'artist_id' => $this->artist->id,
            'artist_name' => $this->artist->name,
            'title' => 'Song Pending Review',
            'message' => "{$this->artist->name} uploaded \"{$this->song->title}\" — review required.",
            'icon' => 'clock',
            'color' => 'yellow',
        ];
    }
}
