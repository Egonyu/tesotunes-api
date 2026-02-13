<?php

namespace App\Modules\Sacco\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Modules\Sacco\Models\SaccoLoan;

class LoanApprovedNotification extends Notification
{
    use Queueable;

    protected SaccoLoan $loan;

    public function __construct(SaccoLoan $loan)
    {
        $this->loan = $loan;
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Loan Application Approved')
            ->greeting('Great News!')
            ->line("Your loan application has been approved.")
            ->line("Loan Number: {$this->loan->loan_number}")
            ->line("Amount: UGX " . number_format($this->loan->principal_amount, 2))
            ->line("Interest Rate: {$this->loan->interest_rate}% per annum")
            ->line("Repayment Period: {$this->loan->repayment_period_months} months")
            ->line("Monthly Payment: UGX " . number_format($this->loan->monthly_repayment, 2))
            ->line('Your loan will be disbursed shortly.')
            ->action('View Loan Details', route('sacco.loans.show', $this->loan->id))
            ->line('Thank you for your patience!');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'loan_approved',
            'loan_id' => $this->loan->id,
            'loan_number' => $this->loan->loan_number,
            'amount' => $this->loan->principal_amount,
            'message' => 'Your loan application has been approved'
        ];
    }
}
