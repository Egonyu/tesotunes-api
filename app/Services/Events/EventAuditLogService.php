<?php

namespace App\Services\Events;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;

class EventAuditLogService
{
    public function log(?User $actor, Event $event, string $action, array $oldValues = [], array $newValues = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => Event::class,
            'auditable_id' => $event->id,
            'old_values' => empty($oldValues) ? null : $oldValues,
            'new_values' => empty($newValues) ? null : $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
        ]);
    }

    public function recentForEvent(Event $event, int $limit = 15): Collection
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->where('auditable_type', Event::class)
            ->where('auditable_id', $event->id)
            ->latest()
            ->limit($limit)
            ->get();
    }
}
