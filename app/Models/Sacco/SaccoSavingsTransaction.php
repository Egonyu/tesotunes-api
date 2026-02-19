<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoSavingsTransaction extends Model
{
    use HasFactory;

    protected $table = 'sacco_savings_transactions';

    protected $fillable = [
        'uuid',
        'transaction_code',
        'account_id',
        'member_id',
        'type',
        'amount_ugx',
        'balance_before_ugx',
        'balance_after_ugx',
        'description',
        'reference_number',
        'status',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'balance_before_ugx' => 'decimal:2',
        'balance_after_ugx' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($txn) {
            if (empty($txn->uuid)) {
                $txn->uuid = (string) Str::uuid();
            }
            if (empty($txn->transaction_code)) {
                $txn->transaction_code = 'TXN'.now()->format('YmdHis').rand(1000, 9999);
            }
        });
    }

    // Relationships
    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoSavingsAccount::class, 'account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    // Scopes
    public function scopeDeposits($query)
    {
        return $query->where('type', 'deposit');
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('type', 'withdrawal');
    }

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeByMember($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }
}
