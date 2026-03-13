<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditLoggingListener
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $user = $this->resolveUser($event) ?? Auth::user();
        $eventName = class_basename($event);
        $eventData = $this->resolveEventData($event);

        Log::channel('audit')->info('Audit event', [
            'event' => get_class($event),
            'event_name' => $eventName,
            'outcome' => $this->resolveOutcome($eventName),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'method' => request()?->method(),
            'timestamp' => now()->toIso8601String(),
            'data' => $eventData,
        ]);

        if ($eventName === 'Failed') {
            Log::channel('security')->warning('auth.event.failed', [
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'attempted_email' => $eventData['credentials']['email'] ?? null,
                'guard' => $eventData['guard'] ?? null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'url' => request()?->fullUrl(),
                'method' => request()?->method(),
            ]);
        }
    }

    /**
     * Laravel event system invokes this method
     */
    public function __invoke(object $event): void
    {
        $this->handle($event);
    }

    private function resolveUser(object $event): mixed
    {
        if (property_exists($event, 'user')) {
            return $event->user;
        }

        return null;
    }

    private function resolveOutcome(string $eventName): string
    {
        return match ($eventName) {
            'Failed' => 'failed',
            'Logout' => 'logout',
            default => 'success',
        };
    }

    private function resolveEventData(object $event): array|string
    {
        if (method_exists($event, 'toArray')) {
            return $event->toArray();
        }

        $data = [];

        foreach (['guard', 'remember', 'credentials'] as $property) {
            if (property_exists($event, $property)) {
                $data[$property] = $event->{$property};
            }
        }

        return empty($data) ? get_class($event) : $data;
    }
}
