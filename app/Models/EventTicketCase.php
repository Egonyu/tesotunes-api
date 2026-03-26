<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventTicketCase extends Model
{
    use HasFactory;

    public const TYPE_REFUND_REQUEST = 'refund_request';

    public const TYPE_PAYMENT_DISPUTE = 'payment_dispute';

    public const ESCALATION_NONE = 'none';

    public const ESCALATION_REVIEW = 'review';

    public const ESCALATION_RESOLVED = 'resolved';

    public const STATUS_OPEN = 'open';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'uuid',
        'event_id',
        'event_attendee_id',
        'payment_id',
        'requested_by_user_id',
        'resolved_by_user_id',
        'case_type',
        'dispute_category',
        'status',
        'escalation_status',
        'reason',
        'gateway_reference',
        'evidence_url',
        'evidence_notes',
        'resolution_notes',
        'requested_refund_amount',
        'approved_refund_amount',
        'resolved_at',
    ];

    protected $casts = [
        'requested_refund_amount' => 'decimal:2',
        'approved_refund_amount' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $case) {
            if (blank($case->uuid)) {
                $case->uuid = (string) Str::uuid();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(EventAttendee::class, 'event_attendee_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
