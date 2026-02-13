<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'member_id',
        'transaction_reference',
        'transaction_type',
        'description',
        'notes',
        'transaction_date',
    ];

    // CRITICAL: Protect financial columns
    protected $guarded = [
        'amount',
        'balance_before',
        'balance_after',
        'processed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    // Transaction types
    const TYPE_DEPOSIT = 'deposit';
    const TYPE_WITHDRAWAL = 'withdrawal';
    const TYPE_TRANSFER = 'transfer';
    const TYPE_LOAN_DISBURSEMENT = 'loan_disbursement';
    const TYPE_LOAN_REPAYMENT = 'loan_repayment';
    const TYPE_DIVIDEND = 'dividend';
    const TYPE_INTEREST = 'interest';
    const TYPE_FEE = 'fee';
    const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Relationships
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoAccount::class, 'account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Scopes
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeDeposits($query)
    {
        return $query->where('transaction_type', self::TYPE_DEPOSIT);
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('transaction_type', self::TYPE_WITHDRAWAL);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('transaction_date', '>=', now()->subDays($days));
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('transaction_date', now()->year)
                     ->whereMonth('transaction_date', now()->month);
    }

    /**
     * Accessors
     */
    public function getIsDebitAttribute(): bool
    {
        return in_array($this->transaction_type, [
            self::TYPE_WITHDRAWAL,
            self::TYPE_LOAN_DISBURSEMENT,
            self::TYPE_FEE,
        ]);
    }

    public function getIsCreditAttribute(): bool
    {
        return in_array($this->transaction_type, [
            self::TYPE_DEPOSIT,
            self::TYPE_LOAN_REPAYMENT,
            self::TYPE_DIVIDEND,
            self::TYPE_INTEREST,
        ]);
    }

    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->is_debit ? '-' : '+';
        return $prefix . 'UGX ' . number_format($this->amount, 2);
    }
}
