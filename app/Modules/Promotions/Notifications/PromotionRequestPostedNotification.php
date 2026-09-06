<?php

namespace App\Modules\Promotions\Notifications;

use App\Modules\Promotions\Models\PromotionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PromotionRequestPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PromotionRequest $promotionRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'promotion_request_posted',
            'promotion_request_id' => $this->promotionRequest->id,
            'promotion_request_uuid' => $this->promotionRequest->uuid,
            'title' => $this->promotionRequest->title,
        ];
    }
}
