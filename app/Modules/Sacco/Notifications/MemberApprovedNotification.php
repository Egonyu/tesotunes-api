<?php

namespace App\Modules\Sacco\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Modules\Sacco\Models\SaccoMember;

class MemberApprovedNotification extends Notification
{
    use Queueable;

    protected SaccoMember $member;

    public function __construct(SaccoMember $member)
    {
        $this->member = $member;
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SACCO Membership Approved')
            ->greeting('Congratulations!')
            ->line('Your SACCO membership application has been approved.')
            ->line("Your membership number is: {$this->member->member_number}")
            ->line('You can now access all SACCO services including:')
            ->line('• Savings and Share Capital Accounts')
            ->line('• Loan Applications')
            ->line('• Dividend Benefits')
            ->action('Access SACCO Dashboard', route('frontend.sacco.dashboard'))
            ->line('Thank you for joining our SACCO!');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'member_approved',
            'member_id' => $this->member->id,
            'member_number' => $this->member->member_number,
            'message' => 'Your SACCO membership has been approved'
        ];
    }
}
