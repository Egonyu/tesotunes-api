<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Channels\ExpoPushChannel;
use App\Models\CatalogClaimRequest;
use App\Traits\ChecksNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CatalogClaimStatusNotification extends Notification implements ShouldQueue
{
    use ChecksNotificationPreferences, Queueable;

    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public function __construct(
        protected CatalogClaimRequest $claim,
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
        $artistName = $this->claim->artist?->stage_name ?? $this->claim->artist?->name ?? 'your artist profile';

        $mail = (new MailMessage)
            ->greeting("Hello {$notifiable->display_name}!");

        return match ($this->status) {
            self::APPROVED => $mail
                ->subject('Artist Claim Approved')
                ->line("Your claim for {$artistName} has been approved.")
                ->line('You can now manage this artist profile from your account.')
                ->action('Open Artist Dashboard', url('/artist')),
            self::REJECTED => $mail
                ->subject('Artist Claim Update')
                ->line("Your claim for {$artistName} was not approved.")
                ->line('Reason: '.($this->reason ?? 'Additional verification is required.'))
                ->action('Review Claim Status', url('/settings/claims')),
            default => $mail
                ->subject('Artist Claim Received')
                ->line("We received your claim for {$artistName}.")
                ->line('Our team will review your request and update you once a decision is made.')
                ->action('Track Claim Status', url('/settings/claims')),
        };
    }

    public function toArray(object $notifiable): array
    {
        $artistName = $this->claim->artist?->stage_name ?? $this->claim->artist?->name ?? 'artist profile';

        return [
            'type' => 'catalog_claim',
            'module' => 'music',
            'claim_id' => $this->claim->id,
            'artist_id' => $this->claim->artist_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'title' => $this->getTitle(),
            'message' => $this->getMessage($artistName),
            'action_url' => $this->status === self::APPROVED ? '/artist' : '/settings/claims',
            'icon' => $this->status === self::APPROVED ? 'check-circle' : ($this->status === self::REJECTED ? 'x-circle' : 'clock'),
            'color' => $this->status === self::APPROVED ? 'green' : ($this->status === self::REJECTED ? 'red' : 'yellow'),
            'priority' => 'high',
        ];
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'body' => $this->getMessage($this->claim->artist?->stage_name ?? $this->claim->artist?->name ?? 'artist profile'),
            'data' => [
                'type' => 'catalog_claim',
                'claimId' => $this->claim->id,
                'status' => $this->status,
                'screen' => $this->status === self::APPROVED ? 'ArtistDashboard' : 'SettingsClaims',
            ],
        ];
    }

    private function getTitle(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Artist Claim Approved',
            self::REJECTED => 'Artist Claim Rejected',
            default => 'Artist Claim Submitted',
        };
    }

    private function getMessage(string $artistName): string
    {
        return match ($this->status) {
            self::APPROVED => "Your claim for {$artistName} has been approved.",
            self::REJECTED => "Your claim for {$artistName} was rejected. ".($this->reason ?? ''),
            default => "Your claim for {$artistName} is pending review.",
        };
    }
}
