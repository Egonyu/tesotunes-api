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
            'promotion_request_id' => $this->application->promotion_request_id,
            'promotion_request_title' => $this->application->promotionRequest?->title,
            'reason' => $this->application->artist_response,
        ];
    }
}
