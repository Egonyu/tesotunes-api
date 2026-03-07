<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoWithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'account_id',
        'request_number',
        'amount_ugx',
        'fee_ugx',
        'net_amount_ugx',
        'withdrawal_method',
        'phone_number',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'processed_at',
        'transaction_reference',
        'rejection_reason',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'fee_ugx' => 'decimal:2',
        'net_amount_ugx' => 'decimal:2',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'fee_ugx' => 0,
        'withdrawal_method' => 'mobile_money',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
            if (empty($request->request_number)) {
                $request->request_number = 'WDR'.now()->format('Ymd').rand(10000, 99999);
            }
            // Calculate net amount
            if ($request->net_amount_ugx <= 0) {
                $request->net_amount_ugx = $request->amount_ugx - ($request->fee_ugx ?? 0);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoSavingsAccount::class, 'account_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
