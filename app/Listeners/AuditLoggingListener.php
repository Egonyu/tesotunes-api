<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;

class AuditLoggingListener
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        // TODO: Implement audit logging
        Log::channel('audit')->info('Audit event', [
            'event' => get_class($event),
            'data' => $event,
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
