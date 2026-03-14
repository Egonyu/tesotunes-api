<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendee extends Model
{
    use HasFactory;

    protected $table = 'event_attendees';

    protected $fillable = [
        'uuid',
        'confirmation_code',
        'event_id',
        'ticket_id',
        'user_id',
        'attendee_name',
        'attendee_email',
        'attendee_phone',
        'price_paid_ugx',
        'price_paid_credits',
        'payment_method',
        'status',
        'confirmed_at',
        'checked_in_at',
        'cancelled_at',
        'qr_code',
        'attendance_type',
        'quantity',
        'amount_paid',
        'payment_reference',
        'payment_status',
        'checked_in_by_user_id',
        'attended_at',
        'attendee_metadata',
        'notes',
    ];

    protected $casts = [
        'price_paid_ugx' => 'decimal:2',
        'price_paid_credits' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attended_at' => 'datetime',
        'attendee_metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Str::uuid();
            }
            if (empty($model->confirmation_code)) {
                $model->confirmation_code = strtoupper(substr(uniqid(), -8));
            }
        });
    }

    // Constants for status values
    const STATUS_PENDING = 'pending';

    const STATUS_CONFIRMED = 'confirmed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_ATTENDED = 'attended';

    const STATUS_NO_SHOW = 'no_show';

    const PAYMENT_METHOD_WALLET = 'wallet';

    const PAYMENT_METHOD_MTN_MOMO = 'mtn_momo';

    const PAYMENT_METHOD_AIRTEL_MONEY = 'airtel_money';

    const PAYMENT_METHOD_CARD = 'card';

    const PAYMENT_METHOD_CREDITS = 'credits';

    const PAYMENT_METHOD_FREE = 'free';

    // Relationships
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'ticket_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'ticket_id');
    }

    public function eventTicket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'ticket_id');
    }

    public function getTicketNumberAttribute(): string
    {
        return $this->confirmation_code;
    }

    public function getTicketCodeAttribute(): string
    {
        return $this->confirmation_code;
    }

    // Status Methods
    public function confirm(?string $paymentReference = null): void
    {
        $payload = [
            'status' => self::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'payment_status' => 'completed',
        ];

        if ($paymentReference) {
            $payload['payment_reference'] = $paymentReference;
        }

        $this->update($payload);

        // If this was a paid ticket, dispatch event for loyalty points
        if (($this->price_paid_ugx ?? 0) > 0 || ($this->amount_paid ?? 0) > 0) {
            \App\Events\TicketPurchased::dispatch($this, $this->ticket, $this->event);
        }
    }

    public function cancel(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function checkIn(): void
    {
        $this->update([
            'status' => self::STATUS_ATTENDED,
            'checked_in_at' => now(),
            'attended_at' => now(),
        ]);

        // Dispatch event for loyalty points
        \App\Events\AttendeeCheckedIn::dispatch($this, $this->event);
    }

    public function markAsNoShow(): void
    {
        $this->update([
            'status' => self::STATUS_NO_SHOW,
        ]);
    }

    // Status Checks
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function hasAttended(): bool
    {
        return $this->status === self::STATUS_ATTENDED || $this->checked_in_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isGuest(): bool
    {
        return is_null($this->user_id);
    }

    // QR Code
    public function generateQrCode(): string
    {
        $qrData = json_encode([
            'confirmation_code' => $this->confirmation_code,
            'event_id' => $this->event_id,
            'attendee_id' => $this->id,
        ]);

        $this->update(['qr_code' => $qrData]);

        return $qrData;
    }
}
