<?php

namespace App\Notifications;

use App\Channels\ExpoPushChannel;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArtistApplicationNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUBMITTED = 'submitted';

    public function __construct(
        protected string $status,
        protected ?string $reason = null
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsByPreference(
            $notifiable,
            ['mail', 'database', ExpoPushChannel::class],
            'music'
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return match ($this->status) {
            self::APPROVED => (new MailMessage)
                ->subject('Welcome to TesoTunes Artists!')
                ->greeting("Congratulations {$notifiable->display_name}!")
                ->line('Your artist application has been **approved**!')
                ->line('You can now upload songs, create albums, and start earning from your music.')
                ->action('Go to Artist Dashboard', url('/artist/dashboard'))
                ->line('Welcome to the TesoTunes artist community!'),

            self::REJECTED => (new MailMessage)
                ->subject('Artist Application Update — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line('Unfortunately, your artist application has been **declined**.')
                ->line('**Reason:** ' . ($this->reason ?? 'Your application did not meet our current requirements.'))
                ->line('You may re-apply after addressing the feedback above.')
                ->action('Re-apply', url('/become-artist'))
                ->line('Thank you for your interest in sharing your music!'),

            default => (new MailMessage)
                ->subject('Artist Application Received — TesoTunes')
                ->greeting("Hi {$notifiable->display_name},")
                ->line('We have received your artist application!')
                ->line('Our team will review it and get back to you shortly.')
                ->action('View Status', url('/settings/artist-status'))
                ->line('Thank you for your patience!'),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'artist_application',
            'module' => 'music',
            'status' => $this->status,
            'reason' => $this->reason,
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'priority' => 'high',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'body' => $this->getMessage(),
            'data' => [
                'type' => 'artist_application',
                'status' => $this->status,
                'screen' => $this->status === self::APPROVED ? 'ArtistDashboard' : 'Settings',
            ],
            'options' => [
                'priority' => 'high',
            ],
        ];
    }

    private function getTitle(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Artist Application Approved!',
            self::REJECTED => 'Artist Application Declined',
            self::SUBMITTED => 'Application Received',
            default => 'Artist Application Update',
        };
    }

    private function getMessage(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Your artist application has been approved! Start uploading your music.',
            self::REJECTED => 'Your artist application was declined. ' . ($this->reason ?? 'Please review and re-apply.'),
            self::SUBMITTED => 'Your artist application has been received. We will review it shortly.',
            default => 'Your artist application status has been updated.',
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
