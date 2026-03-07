<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoFine extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'fine_number',
        'reason',
        'description',
        'amount_ugx',
        'amount_paid_ugx',
        'due_date',
        'paid_at',
        'status',
        'issued_by',
        'waived_by',
        'waiver_reason',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'amount_paid_ugx' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'date',
    ];

    protected $attributes = [
        'status' => 'pending',
        'amount_paid_ugx' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($fine) {
            if (empty($fine->uuid)) {
                $fine->uuid = (string) Str::uuid();
            }
            if (empty($fine->fine_number)) {
                $fine->fine_number = 'FIN'.now()->format('Ymd').rand(10000, 99999);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeWaived($query)
    {
        return $query->where('status', 'waived');
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->amount_paid_ugx >= $this->amount_ugx;
    }

    public function getBalanceAttribute(): float
    {
        return max(0, $this->amount_ugx - $this->amount_paid_ugx);
    }
}
