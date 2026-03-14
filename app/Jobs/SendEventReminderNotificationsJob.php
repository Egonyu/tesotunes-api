<?php

namespace App\Jobs;

use App\Services\Events\EventNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventReminderNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $hoursUntil = 24,
    ) {}

    public function handle(EventNotificationService $eventNotificationService): void
    {
        $eventNotificationService->sendUpcomingEventReminders($this->hoursUntil);
    }
}
