<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminArtistApplicationPendingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $applicant,
        protected Artist $artist
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New Artist Application: {$this->artist->stage_name}")
            ->greeting("Hello {$notifiable->display_name}!")
            ->line("{$this->applicant->display_name} submitted a new artist application.")
            ->line("Stage name: {$this->artist->stage_name}")
            ->action('Review Artist Application', url("/admin/artists/{$this->artist->id}"))
            ->line('Please review and approve or reject the application.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_artist_application_pending',
            'module' => 'music',
            'artist_id' => $this->artist->id,
            'applicant_user_id' => $this->applicant->id,
            'title' => 'Artist Application Pending',
            'message' => "{$this->artist->stage_name} submitted a new artist application for review.",
            'action_url' => "/admin/artists/{$this->artist->id}",
            'icon' => 'user-plus',
            'color' => 'yellow',
            'priority' => 'high',
        ];
    }
}
