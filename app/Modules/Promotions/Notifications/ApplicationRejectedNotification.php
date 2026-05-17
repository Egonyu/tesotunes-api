<?php

namespace App\Modules\Promotions\Notifications;

use App\Modules\Promotions\Models\PromotionApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ApplicationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PromotionApplication $application) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'application_rejected',
            'application_id' => $this->application->id,
            'opportunity_id' => $this->application->opportunity_id,
            'opportunity_title' => $this->application->opportunity?->title,
            'reason' => $this->application->artist_response,
        ];
    }
}
