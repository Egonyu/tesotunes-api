<?php

namespace App\Notifications;

use App\Channels\AppNotificationChannel;
use App\Models\Podcast;
use App\Notifications\Concerns\BuildsFrontendUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PodcastStatusNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable;

    protected Podcast $podcast;

    protected string $status;

    protected ?string $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct(Podcast $podcast, string $status, ?string $reason = null)
    {
        $this->podcast = $podcast;
        $this->status = $status;
        $this->reason = $reason;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting("Hello {$notifiable->display_name}!");

        switch ($this->status) {
            case 'published':
                $mail->subject('✅ Your Podcast Has Been Approved!')
                    ->line("Great news! Your podcast **{$this->podcast->title}** has been approved and is now live.")
                    ->line('Your podcast is now visible to listeners and can receive subscriptions.')
                    ->action('View Your Podcast', $this->frontendUrl("/podcasts/{$this->podcast->slug}"));
                break;

            case 'rejected':
                $mail->subject('❌ Podcast Submission Rejected')
                    ->line("Unfortunately, your podcast **{$this->podcast->title}** has been rejected.")
                    ->line('**Reason:** '.($this->reason ?? 'No specific reason provided.'))
                    ->line('Please review the feedback and resubmit after making necessary changes.')
                    ->action('Edit Your Podcast', $this->frontendUrl("/artist/podcasts/{$this->podcast->id}/edit"));
                break;

            case 'suspended':
                $mail->subject('⚠️ Podcast Suspended')
                    ->line("Your podcast **{$this->podcast->title}** has been suspended.")
                    ->line('**Reason:** '.($this->reason ?? 'Policy violation.'))
                    ->line('If you believe this was in error, please contact support.');
                break;

            default:
                $mail->subject('Podcast Status Update')
                    ->line("Your podcast **{$this->podcast->title}** status has been updated to: {$this->status}");
        }

        return $mail->line('Thank you for using TesoTunes!');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $icons = [
            'published' => '✅',
            'rejected' => '❌',
            'suspended' => '⚠️',
        ];

        return [
            'type' => 'podcast_status',
            'icon' => $icons[$this->status] ?? '📢',
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'podcast_id' => $this->podcast->id,
            'podcast_title' => $this->podcast->title,
            'status' => $this->status,
            'reason' => $this->reason,
            'action_url' => $this->status === 'published'
                ? $this->frontendUrl("/podcasts/{$this->podcast->slug}")
                : $this->frontendUrl("/artist/podcasts/{$this->podcast->id}/edit"),
        ];
    }

    protected function getTitle(): string
    {
        return match ($this->status) {
            'published' => 'Podcast Approved!',
            'rejected' => 'Podcast Rejected',
            'suspended' => 'Podcast Suspended',
            default => 'Podcast Status Updated',
        };
    }

    protected function getMessage(): string
    {
        return match ($this->status) {
            'published' => "Your podcast \"{$this->podcast->title}\" is now live!",
            'rejected' => "Your podcast \"{$this->podcast->title}\" was rejected. ".($this->reason ?? ''),
            'suspended' => "Your podcast \"{$this->podcast->title}\" has been suspended.",
            default => "Your podcast status has been updated to: {$this->status}",
        };
    }
}
