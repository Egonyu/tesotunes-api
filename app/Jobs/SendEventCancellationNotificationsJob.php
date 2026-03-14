<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\Events\EventNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventCancellationNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly ?string $reason = null,
    ) {}

    public function handle(EventNotificationService $eventNotificationService): void
    {
        $event = Event::find($this->eventId);
        if (! $event) {
            return;
        }

        $eventNotificationService->sendCancellationNotifications($event, $this->reason);
    }
}
