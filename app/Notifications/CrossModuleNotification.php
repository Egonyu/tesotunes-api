<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class CrossModuleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $module;
    protected string $type;
    protected string $title;
    protected string $message;
    protected array $data;
    protected ?string $actionUrl;
    protected ?string $actionText;

    public function __construct(
        string $module,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $actionUrl = null,
        ?string $actionText = null
    ) {
        $this->module = $module;
        $this->following_type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->data = $data;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // Send email for critical notifications
        if ($this->isCritical()) {
            $channels[] = 'mail';
        }

        // Add push notification channel if enabled
        if ($this->shouldSendPush()) {
            $channels[] = 'push';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->getEmailSubject())
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->message);

        if ($this->actionUrl && $this->actionText) {
            $mail->action($this->actionText, $this->actionUrl);
        }

        return $mail->line('Thank you for using LineOne Music Platform!');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'module' => $this->module,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
            'action_url' => $this->actionUrl,
            'action_text' => $this->actionText,
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'priority' => $this->getPriority(),
        ];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Determine if this is a critical notification
     */
    protected function isCritical(): bool
    {
        $criticalTypes = [
            'payment_failed',
            'loan_overdue',
            'account_suspended',
            'distribution_rejected',
            'copyright_claim',
            'security_alert',
        ];

        return in_array($this->type, $criticalTypes);
    }

    /**
     * Determine if push notification should be sent
     */
    protected function shouldSendPush(): bool
    {
        $pushTypes = [
            'song_approved',
            'podcast_published',
            'new_subscriber',
            'payout_processed',
            'loan_approved',
            'order_received',
        ];

        return in_array($this->type, $pushTypes);
    }

    /**
     * Get email subject based on module and type
     */
    protected function getEmailSubject(): string
    {
        return match ($this->module) {
            'music' => $this->getMusicEmailSubject(),
            'podcast' => $this->getPodcastEmailSubject(),
            'store' => $this->getStoreEmailSubject(),
            'sacco' => $this->getSaccoEmailSubject(),
            default => $this->title,
        };
    }

    /**
     * Get music module email subjects
     */
    protected function getMusicEmailSubject(): string
    {
        return match ($this->type) {
            'song_approved' => 'Your song has been approved for distribution!',
            'song_rejected' => 'Your song submission needs attention',
            'distribution_live' => 'Your music is now live on streaming platforms!',
            'royalty_payment' => 'You\'ve received a royalty payment',
            'copyright_claim' => 'URGENT: Copyright claim on your music',
            default => $this->title,
        };
    }

    /**
     * Get podcast module email subjects
     */
    protected function getPodcastEmailSubject(): string
    {
        return match ($this->type) {
            'podcast_approved' => 'Your podcast has been approved!',
            'episode_published' => 'Your new episode is live',
            'new_subscriber' => 'You have a new podcast subscriber',
            'sponsor_inquiry' => 'New sponsorship inquiry for your podcast',
            default => $this->title,
        };
    }

    /**
     * Get store module email subjects
     */
    protected function getStoreEmailSubject(): string
    {
        return match ($this->type) {
            'product_approved' => 'Your product has been approved for sale',
            'order_received' => 'You have a new order!',
            'payment_received' => 'Payment received for your order',
            'low_inventory' => 'Low inventory alert',
            default => $this->title,
        };
    }

    /**
     * Get SACCO module email subjects
     */
    protected function getSaccoEmailSubject(): string
    {
        return match ($this->type) {
            'membership_approved' => 'Welcome to the SACCO!',
            'loan_approved' => 'Your loan application has been approved',
            'loan_disbursed' => 'Your loan has been disbursed',
            'payment_due' => 'Loan payment reminder',
            'loan_overdue' => 'URGENT: Overdue loan payment',
            default => $this->title,
        };
    }

    /**
     * Get notification icon
     */
    protected function getIcon(): string
    {
        return match ($this->module) {
            'music' => match ($this->type) {
                'song_approved', 'song_published' => 'check-circle',
                'song_rejected' => 'x-circle',
                'distribution_live' => 'globe',
                'royalty_payment' => 'currency-dollar',
                'copyright_claim' => 'exclamation-triangle',
                default => 'music-note',
            },
            'podcast' => match ($this->type) {
                'podcast_approved', 'episode_published' => 'check-circle',
                'new_subscriber' => 'user-plus',
                'sponsor_inquiry' => 'currency-dollar',
                default => 'microphone',
            },
            'store' => match ($this->type) {
                'product_approved' => 'check-circle',
                'order_received' => 'shopping-bag',
                'payment_received' => 'currency-dollar',
                'low_inventory' => 'exclamation-triangle',
                default => 'shopping-cart',
            },
            'sacco' => match ($this->type) {
                'membership_approved', 'loan_approved' => 'check-circle',
                'loan_disbursed' => 'banknotes',
                'payment_due', 'loan_overdue' => 'clock',
                default => 'building-library',
            },
            default => 'bell',
        };
    }

    /**
     * Get notification color
     */
    protected function getColor(): string
    {
        return match ($this->type) {
            'song_approved', 'podcast_approved', 'product_approved', 'membership_approved', 'loan_approved' => 'green',
            'song_rejected', 'podcast_rejected', 'product_rejected', 'membership_rejected', 'loan_rejected' => 'red',
            'copyright_claim', 'payment_failed', 'loan_overdue', 'account_suspended' => 'red',
            'payment_due', 'low_inventory' => 'yellow',
            'royalty_payment', 'payment_received', 'loan_disbursed', 'new_subscriber' => 'green',
            default => 'blue',
        };
    }

    /**
     * Get notification priority
     */
    protected function getPriority(): string
    {
        return match ($this->type) {
            'copyright_claim', 'account_suspended', 'payment_failed', 'loan_overdue' => 'high',
            'payment_due', 'low_inventory', 'security_alert' => 'medium',
            default => 'normal',
        };
    }
}