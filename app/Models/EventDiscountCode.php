<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EventDiscountCode extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED_AMOUNT = 'fixed_amount';

    protected $fillable = [
        'uuid',
        'event_id',
        'name',
        'code',
        'discount_type',
        'discount_value',
        'max_discount_ugx',
        'usage_limit',
        'usage_count',
        'min_order_amount_ugx',
        'applies_to_ticket_ids',
        'starts_at',
        'ends_at',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount_ugx' => 'decimal:2',
        'min_order_amount_ugx' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'applies_to_ticket_ids' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            $model->code = strtoupper(trim((string) $model->code));
        });

        static::saving(function (self $model) {
            $model->code = strtoupper(trim((string) $model->code));
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isCurrentlyRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->usage_limit !== null && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function appliesToTicketId(int $ticketId): bool
    {
        $ticketIds = collect($this->applies_to_ticket_ids ?? [])
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        if ($ticketIds->isEmpty()) {
            return true;
        }

        return $ticketIds->contains($ticketId);
    }

    public function appliesToAnyTicket(Collection $tickets): bool
    {
        return $tickets->contains(fn ($ticket) => $this->appliesToTicketId((int) $ticket->id));
    }

    public function meetsMinimumOrder(float $amount): bool
    {
        return $amount >= (float) ($this->min_order_amount_ugx ?? 0);
    }

    public function calculateDiscountForAmount(float $amount): float
    {
        $amount = max(0, round($amount, 2));

        if ($amount <= 0) {
            return 0.0;
        }

        $discount = match ($this->discount_type) {
            self::TYPE_FIXED_AMOUNT => (float) $this->discount_value,
            default => $amount * (((float) $this->discount_value) / 100),
        };

        if ($this->max_discount_ugx !== null) {
            $discount = min($discount, (float) $this->max_discount_ugx);
        }

        return round(min($amount, max(0, $discount)), 2);
    }
}
