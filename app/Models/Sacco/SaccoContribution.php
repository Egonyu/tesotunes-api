<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoContribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'contribution_number',
        'type',
        'amount_ugx',
        'payment_method',
        'transaction_reference',
        'contribution_date',
        'period',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'contribution_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'pending',
        'type' => 'monthly',
        'payment_method' => 'mobile_money',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($contribution) {
            if (empty($contribution->uuid)) {
                $contribution->uuid = (string) Str::uuid();
            }
            if (empty($contribution->contribution_number)) {
                $contribution->contribution_number = 'CTB' . now()->format('Ymd') . rand(10000, 99999);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
