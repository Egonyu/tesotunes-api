<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'data',
        'ip_address',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    // Helper methods
    public static function logActivity(int $userId, string $event, array $data = []): void
    {
        static::create([
            'user_id' => $userId,
            'event' => $event,
            'data' => $data,
            'ip_address' => request()->ip(),
        ]);
    }

    public static function logAuthEvent(int $userId, string $event): void
    {
        static::logActivity($userId, $event, [
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    public static function logPermissionChange(int $userId, string $action, array $details): void
    {
        static::logActivity($userId, "permission_{$action}", $details);
    }

    // Scopes
    public function scopeByEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
