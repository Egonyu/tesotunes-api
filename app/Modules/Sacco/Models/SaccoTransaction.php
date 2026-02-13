<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoTransaction extends Model
{
    protected $table = 'sacco_savings_transactions';

    protected static function booted(): void
    {
        static::creating(function (SaccoTransaction $tx) {
            if (empty($tx->uuid)) {
                $tx->uuid = (string) Str::uuid();
            }
            if (empty($tx->transaction_code)) {
                $tx->transaction_code = 'TXN' . strtoupper(Str::random(10));
            }
        });
    }
    
    /**
     * Fillable fields matching actual sacco_savings_transactions table:
     * uuid, transaction_code, account_id, member_id, type (enum: deposit,withdrawal,interest,fee,transfer_in,transfer_out),
     * amount_ugx, balance_before_ugx, balance_after_ugx, description, reference_number,
     * status (enum: pending,completed,failed,reversed)
     */
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

    /**
     * Get the member
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    /**
     * Get the account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoAccount::class, 'account_id');
    }

    /**
     * Get the loan (if applicable)
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute(): string
    {
        return 'UGX ' . number_format($this->amount, 2);
    }

    /**
     * Get transaction type label
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->transaction_type) {
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'loan_disbursement' => 'Loan Disbursement',
            'loan_repayment' => 'Loan Repayment',
            'dividend' => 'Dividend Payment',
            'interest' => 'Interest Earned',
            'fee' => 'Fee Payment',
            default => 'Transaction'
        };
    }

    /**
     * Get status badge color
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'processing' => 'info',
            'completed' => 'success',
            'failed' => 'danger',
            'reversed' => 'dark',
            default => 'secondary'
        };
    }
}
