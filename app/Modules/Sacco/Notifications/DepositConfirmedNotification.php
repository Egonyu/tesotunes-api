<?php

namespace App\Modules\Sacco\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Modules\Sacco\Models\SaccoTransaction;

class DepositConfirmedNotification extends Notification
{
    use Queueable;

    protected SaccoTransaction $transaction;

    public function __construct(SaccoTransaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deposit Confirmed')
            ->greeting('Transaction Successful')
            ->line('Your deposit has been confirmed and credited to your account.')
            ->line("Transaction Number: {$this->transaction->transaction_number}")
            ->line("Amount: UGX " . number_format($this->transaction->amount, 2))
            ->line("New Balance: UGX " . number_format($this->transaction->balance_after, 2))
            ->action('View Transaction', route('sacco.transactions'))
            ->line('Thank you for your continued savings!');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'deposit_confirmed',
            'transaction_id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'balance_after' => $this->transaction->balance_after,
            'message' => 'Your deposit has been confirmed'
        ];
    }
}
