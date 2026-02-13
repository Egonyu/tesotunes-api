<?php

namespace App\Modules\Sacco\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Modules\Sacco\Models\SaccoLoanRepayment;

class RepaymentDueNotification extends Notification
{
    use Queueable;

    protected SaccoLoanRepayment $repayment;

    public function __construct(SaccoLoanRepayment $repayment)
    {
        $this->repayment = $repayment;
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $daysUntilDue = now()->diffInDays($this->repayment->due_date, false);
        
        return (new MailMessage)
            ->subject('Loan Repayment Due Reminder')
            ->greeting('Repayment Reminder')
            ->line("Your loan repayment is due in {$daysUntilDue} days.")
            ->line("Due Date: {$this->repayment->due_date->format('F d, Y')}")
            ->line("Amount Due: UGX " . number_format($this->repayment->amount_due, 2))
            ->line("Loan Number: {$this->repayment->loan->loan_number}")
            ->action('Make Payment', route('sacco.loans.show', $this->repayment->loan_id))
            ->line('Please ensure timely payment to maintain your good credit standing.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'repayment_due',
            'repayment_id' => $this->repayment->id,
            'loan_id' => $this->repayment->loan_id,
            'amount_due' => $this->repayment->amount_due,
            'due_date' => $this->repayment->due_date,
            'message' => 'Loan repayment due soon'
        ];
    }
}
