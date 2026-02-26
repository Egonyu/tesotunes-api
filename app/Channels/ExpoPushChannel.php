<?php

namespace App\Channels;

use App\Services\PushNotificationService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ExpoPushChannel
{
    public function __construct(
        protected PushNotificationService $pushService
    ) {}

    /**
     * Send the given notification via Expo Push.
     *
     * Notification classes must implement toExpoPush($notifiable):
     * Returns ['title' => string, 'body' => string, 'data' => array, 'options' => array]
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpoPush')) {
            Log::warning('Notification missing toExpoPush method', [
                'notification' => get_class($notification),
            ]);

            return;
        }

        $message = $notification->toExpoPush($notifiable);

        if (empty($message)) {
            return;
        }

        $title = $message['title'] ?? 'TesoTunes';
        $body = $message['body'] ?? '';
        $data = $message['data'] ?? [];
        $options = $message['options'] ?? [];

        // Tag push data with the notification type for frontend routing
        $data['notification_id'] = $notification->id ?? null;
        $data['notification_class'] = class_basename($notification);

        $this->pushService->sendNotificationToUser(
            $notifiable,
            $title,
            $body,
            $data,
            $options
        );
    }
}
