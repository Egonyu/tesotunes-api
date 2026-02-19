<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentIssue extends Model
{
    use HasFactory;

    // Issue types
    const TYPE_STUCK_PROCESSING = 'stuck_processing';

    const TYPE_PROVIDER_ERROR = 'provider_error';

    const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';

    const TYPE_DUPLICATE_CHARGE = 'duplicate_charge';

    const TYPE_TIMEOUT = 'timeout';

    const TYPE_WEBHOOK_MISSING = 'webhook_missing';

    // Issue statuses
    const STATUS_OPEN = 'open';

    const STATUS_INVESTIGATING = 'investigating';

    const STATUS_RESOLVED = 'resolved';

    const STATUS_ESCALATED = 'escalated';

    const STATUS_CLOSED = 'closed';

    // Resolution types
    const RESOLUTION_AUTO_RESOLVED = 'auto_resolved';

    const RESOLUTION_MANUAL = 'manual';

    const RESOLUTION_REFUNDED = 'refunded';

    const RESOLUTION_RETRIED = 'retried';

    const RESOLUTION_FALSE_POSITIVE = 'false_positive';

    protected $fillable = [
        'payment_id',
        'issue_type',
        'title',
        'description',
        'status',
        'severity',
        'money_deducted',
        'service_delivered',
        'provider_status',
        'resolution_type',
        'resolution_notes',
        'resolved_at',
        'resolved_by',
        'metadata',
        'auto_resolve_attempts',
    ];

    protected $casts = [
        'money_deducted' => 'boolean',
        'service_delivered' => 'boolean',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'auto_resolve_attempts' => 'integer',
    ];

    /**
     * The payment this issue belongs to
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The user who resolved this issue
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('issue_type', $type);
    }

    public function scopeHighSeverity($query)
    {
        return $query->whereIn('severity', ['critical', 'high']);
    }

    // Helpers
    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function markAsResolved(string $resolutionType, string $notes = '', ?int $resolvedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolution_type' => $resolutionType,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
        ]);
    }

    public function escalate(): void
    {
        $this->update(['status' => self::STATUS_ESCALATED]);
    }

    public function incrementAutoResolveAttempts(): void
    {
        $this->increment('auto_resolve_attempts');
    }
}
