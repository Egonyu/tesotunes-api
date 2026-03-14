<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\Song;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SongModerationNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PENDING_REVIEW = 'pending_review';

    public function __construct(
        protected Song $song,
        protected string $status,
        protected ?string $reason = null
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
        $mail = (new MailMessage)
            ->greeting("Hello {$notifiable->display_name}!");

        return match ($this->status) {
            self::APPROVED => $mail
                ->subject('Your Song Has Been Approved!')
                ->line("Great news! Your song **{$this->song->title}** has been approved and is now live on TesoTunes.")
                ->action('View Song', url("/songs/{$this->song->slug}"))
                ->line('Thank you for sharing your music!'),

            self::REJECTED => $mail
                ->subject('Song Submission Rejected')
                ->line("Unfortunately, your song **{$this->song->title}** has been rejected.")
                ->line('**Reason:** '.($this->reason ?? 'No specific reason provided.'))
                ->line('Please review the feedback and re-upload after making necessary changes.')
                ->action('Upload New Song', url('/artist/upload'))
                ->line('We look forward to hearing your music!'),

            default => $mail
                ->subject('Song Status Update')
                ->line("Your song **{$this->song->title}** is pending review.")
                ->line('Our team will review it shortly. You will be notified once a decision is made.')
                ->action('View Your Uploads', url('/artist/songs')),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'song_moderation',
            'module' => 'music',
            'song_id' => $this->song->id,
            'song_title' => $this->song->title,
            'status' => $this->status,
            'reason' => $this->reason,
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'body' => $this->getMessage(),
            'data' => [
                'type' => 'song_moderation',
                'songId' => $this->song->id,
                'status' => $this->status,
                'screen' => $this->status === self::APPROVED ? 'SongDetail' : 'ArtistSongs',
                'params' => ['songId' => $this->song->id],
            ],
        ];
    }

    private function getTitle(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Song Approved!',
            self::REJECTED => 'Song Rejected',
            self::PENDING_REVIEW => 'Song Under Review',
            default => 'Song Status Update',
        };
    }

    private function getMessage(): string
    {
        return match ($this->status) {
            self::APPROVED => "Your song \"{$this->song->title}\" is now live!",
            self::REJECTED => "Your song \"{$this->song->title}\" was rejected. ".($this->reason ?? ''),
            self::PENDING_REVIEW => "Your song \"{$this->song->title}\" is being reviewed.",
            default => "Your song \"{$this->song->title}\" status has been updated.",
        };
    }

    private function getIcon(): string
    {
        return match ($this->status) {
            self::APPROVED => 'check-circle',
            self::REJECTED => 'x-circle',
            default => 'clock',
        };
    }

    private function getColor(): string
    {
        return match ($this->status) {
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            default => 'yellow',
        };
    }
}
