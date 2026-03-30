<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Models\CatalogClaimRequest;
use App\Notifications\Concerns\BuildsFrontendUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminCatalogClaimPendingNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable;

    public function __construct(
        protected CatalogClaimRequest $claim
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $artistName = $this->claim->artist?->stage_name ?? $this->claim->artist?->name ?? 'artist profile';
        $claimantName = $this->claim->claimant?->display_name ?? $this->claim->claimant?->name ?? 'A user';

        return (new MailMessage)
            ->subject("Catalog Claim Pending: {$artistName}")
            ->greeting("Hello {$notifiable->display_name}!")
            ->line("{$claimantName} submitted a claim for {$artistName}.")
            ->action('Review Claim Request', $this->frontendUrl('/admin/catalog/claims'))
            ->line('Please review the submitted evidence and make a decision.');
    }

    public function toArray(object $notifiable): array
    {
        $artistName = $this->claim->artist?->stage_name ?? $this->claim->artist?->name ?? 'artist profile';
        $claimantName = $this->claim->claimant?->display_name ?? $this->claim->claimant?->name ?? 'A user';

        return [
            'type' => 'admin_catalog_claim_pending',
            'module' => 'music',
            'claim_id' => $this->claim->id,
            'artist_id' => $this->claim->artist_id,
            'claimant_user_id' => $this->claim->claimant_user_id,
            'title' => 'Catalog Claim Pending',
            'message' => "{$claimantName} submitted a claim for {$artistName}.",
            'action_url' => '/admin/catalog/claims',
            'icon' => 'shield-check',
            'color' => 'yellow',
            'priority' => 'high',
        ];
    }
}
