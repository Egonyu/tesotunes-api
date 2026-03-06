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
        $user = Auth::user();

        Log::channel('audit')->info('Audit event', [
            'event' => get_class($event),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'method' => request()?->method(),
            'timestamp' => now()->toIso8601String(),
            'data' => method_exists($event, 'toArray') ? $event->toArray() : get_class($event),
        ]);
    }

    /**
     * Laravel event system invokes this method
     */
    public function __invoke(object $event): void
    {
        $this->handle($event);
    }
}
