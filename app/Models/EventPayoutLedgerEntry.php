<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventPayoutLedgerEntry extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'event_id',
        'organizer_id',
        'payment_id',
        'order_id',
        'payment_reference',
        'currency',
        'ticket_quantity',
        'gross_revenue',
        'customer_paid_total',
        'tesotunes_fee_revenue',
        'platform_commission_amount',
        'processing_fee_amount',
        'organizer_net_amount',
        'fee_source',
        'payout_status',
        'attribution_label',
        'attribution',
        'metadata',
        'occurred_at',
        'payout_ready_at',
        'paid_out_at',
        'failed_at',
    ];

    protected $casts = [
        'ticket_quantity' => 'integer',
        'gross_revenue' => 'decimal:2',
        'customer_paid_total' => 'decimal:2',
        'tesotunes_fee_revenue' => 'decimal:2',
        'platform_commission_amount' => 'decimal:2',
        'processing_fee_amount' => 'decimal:2',
        'organizer_net_amount' => 'decimal:2',
        'attribution' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'payout_ready_at' => 'datetime',
        'paid_out_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (blank($entry->uuid)) {
                $entry->uuid = (string) Str::uuid();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
