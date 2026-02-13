<?php

namespace App\Notifications;

use App\Models\SaccoLoan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected SaccoLoan $loan;
    protected string $status;
    protected ?string $notes;

    /**
     * Create a new notification instance.
     */
    public function __construct(SaccoLoan $loan, string $status, ?string $notes = null)
    {
        $this->loan = $loan;
        $this->status = $status;
        $this->notes = $notes;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting("Hello {$notifiable->display_name}!");

        switch ($this->status) {
            case 'approved':
                $mail->subject('✅ Your Loan Has Been Approved!')
                     ->line("Great news! Your loan application for **UGX " . number_format($this->loan->amount) . "** has been approved.")
                     ->line('**Loan Details:**')
                     ->line("- Amount: UGX " . number_format($this->loan->amount))
                     ->line("- Interest Rate: {$this->loan->interest_rate}%")
                     ->line("- Term: {$this->loan->term_months} months")
                     ->line("- Due Date: " . ($this->loan->due_date?->format('M d, Y') ?? 'TBD'))
                     ->action('View Loan Details', url('/sacco/loans'))
                     ->line('Funds will be disbursed to your account shortly.');
                break;

            case 'rejected':
                $mail->subject('❌ Loan Application Rejected')
                     ->line("Unfortunately, your loan application for **UGX " . number_format($this->loan->amount) . "** has been rejected.");
                
                if ($this->notes) {
                    $mail->line('**Reason:** ' . $this->notes);
                }
                
                $mail->line('You may reapply after addressing the issues mentioned.')
                     ->action('Apply Again', url('/sacco/loans/apply'));
                break;

            case 'disbursed':
                $mail->subject('💰 Loan Disbursed!')
                     ->line("Your approved loan of **UGX " . number_format($this->loan->amount) . "** has been disbursed.")
                     ->line('The funds have been credited to your account.')
                     ->line("First repayment due: " . ($this->loan->due_date?->format('M d, Y') ?? 'Check your account'))
                     ->action('View Repayment Schedule', url('/sacco/loans'));
                break;

            default:
                $mail->subject('Loan Status Update')
                     ->line("Your loan application status has been updated to: **{$this->status}**")
                     ->action('View Details', url('/sacco/loans'));
        }

        return $mail->line('Thank you for being a SACCO member!');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $icons = [
            'approved' => '✅',
            'rejected' => '❌',
            'disbursed' => '💰',
            'pending' => '⏳',
        ];

        return [
            'type' => 'loan_status',
            'icon' => $icons[$this->status] ?? '📋',
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'loan_id' => $this->loan->id,
            'amount' => $this->loan->amount,
            'status' => $this->status,
            'notes' => $this->notes,
            'action_url' => url('/sacco/loans'),
        ];
    }

    protected function getTitle(): string
    {
        return match($this->status) {
            'approved' => 'Loan Approved!',
            'rejected' => 'Loan Rejected',
            'disbursed' => 'Loan Disbursed',
            default => 'Loan Status Updated',
        };
    }

    protected function getMessage(): string
    {
        $amount = number_format($this->loan->amount);
        return match($this->status) {
            'approved' => "Your loan of UGX {$amount} has been approved!",
            'rejected' => "Your loan application was rejected. " . ($this->notes ?? ''),
            'disbursed' => "UGX {$amount} has been disbursed to your account.",
            default => "Your loan status is now: {$this->status}",
        };
    }
}
