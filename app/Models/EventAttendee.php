<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Carbon\Carbon;

class EventAttendee extends Model
{
    use HasFactory;

    protected $table = 'event_registrations';

    protected $fillable = [
        'uuid',
        'confirmation_code',
        'event_id',
        'ticket_type_id',
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
        'notes',
        // Legacy fields
        'event_ticket_id',
        'ticket_number',
        'attendance_type',
        'quantity',
        'amount_paid',
        'payment_reference',
        'payment_status',
        'checked_in_by_user_id',
        'attended_at',
        'attendee_metadata'
    ];

    protected $casts = [
        'price_paid_ugx' => 'decimal:2',
        'price_paid_credits' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attended_at' => 'datetime',
        'attendee_metadata' => 'array'
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

    const PAYMENT_METHOD_UGX = 'ugx';
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
        return $this->belongsTo(EventTicket::class, 'ticket_type_id');
    }

    // Legacy relationship
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'event_ticket_id');
    }

    // Status Methods
    public function confirm(): void
    {
        $this->update([
            'status' => self::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
        
        // If this was a paid ticket, dispatch event for loyalty points
        if (($this->price_paid_ugx ?? 0) > 0 || ($this->amount_paid ?? 0) > 0) {
            \App\Events\TicketPurchased::dispatch($this);
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
        ]);
        
        // Dispatch event for loyalty points
        \App\Events\AttendeeCheckedIn::dispatch($this);
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
        return $this->status === self::STATUS_ATTENDED;
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
