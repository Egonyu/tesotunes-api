<?php

namespace App\Channels;

use App\Models\Notification as AppNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AppNotificationChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! isset($notifiable->id)) {
            return;
        }

        $payload = $this->resolvePayload($notification, $notifiable);
        if (empty($payload)) {
            return;
        }

        AppNotification::create([
            'user_id' => $notifiable->id,
            'type' => $payload['type'] ?? Str::snake(class_basename($notification)),
            'category' => $payload['category'] ?? $payload['module'] ?? 'general',
            'title' => $payload['title'] ?? Str::headline($payload['type'] ?? class_basename($notification)),
            'message' => $payload['message'] ?? '',
            'action_url' => $payload['action_url'] ?? null,
            'action_text' => $payload['action_text'] ?? null,
            'icon' => $payload['icon'] ?? null,
            'image' => $payload['image'] ?? null,
            'priority' => $payload['priority'] ?? 'normal',
            'data' => $payload,
        ]);
    }

    private function resolvePayload(Notification $notification, object $notifiable): array
    {
        if (method_exists($notification, 'toAppNotification')) {
            return (array) $notification->toAppNotification($notifiable);
        }

        if (method_exists($notification, 'toDatabase')) {
            return (array) $notification->toDatabase($notifiable);
        }

        if (method_exists($notification, 'toArray')) {
            return (array) $notification->toArray($notifiable);
        }

        return [];
    }
}
