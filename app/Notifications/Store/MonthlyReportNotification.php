<?php

namespace App\Notifications\Store;

use App\Modules\Store\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Store $store,
        protected array $reportPaths,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Monthly Report — {$this->store->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your monthly store report for **{$this->store->name}** is ready.")
            ->line('Reports include sales, product performance, and customer analytics.')
            ->action('View Store Dashboard', url("/store/{$this->store->slug}/dashboard"));

        foreach ($this->reportPaths as $label => $path) {
            if ($path && file_exists(storage_path("app/{$path}"))) {
                $mail->attach(storage_path("app/{$path}"), [
                    'as' => basename($path),
                    'mime' => 'text/csv',
                ]);
            }
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'monthly_report',
            'store_id' => $this->store->id,
            'store_name' => $this->store->name,
            'message' => "Your monthly report for {$this->store->name} is ready.",
            'report_paths' => $this->reportPaths,
        ];
    }
}
