<?php

namespace App\Modules\Promotions\Notifications;

use App\Modules\Promotions\Models\PromotionOpportunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OpportunityPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PromotionOpportunity $opportunity) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'opportunity_posted',
            'opportunity_id' => $this->opportunity->id,
            'opportunity_uuid' => $this->opportunity->uuid,
            'title' => $this->opportunity->title,
        ];
    }
}
