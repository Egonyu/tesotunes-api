<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoAccount extends Model
{
    protected $table = 'sacco_savings_accounts';

    protected static function booted(): void
    {
        static::creating(function (SaccoAccount $account) {
            if (empty($account->uuid)) {
                $account->uuid = (string) Str::uuid();
            }
        });
    }
    
    /**
     * Fillable fields matching actual sacco_savings_accounts table:
     * uuid, account_number, member_id, account_type (enum: regular,fixed_deposit,target,retirement),
     * account_name, balance_ugx, interest_rate, accrued_interest_ugx, minimum_balance_ugx,
     * withdrawal_limit_monthly, maturity_date, allow_early_withdrawal, status (enum: active,dormant,closed)
     */
    protected $fillable = [
        'uuid',
        'account_number',
        'member_id',
        'account_type',
        'account_name',
        'balance_ugx',
        'interest_rate',
        'accrued_interest_ugx',
        'minimum_balance_ugx',
        'withdrawal_limit_monthly',
        'maturity_date',
        'allow_early_withdrawal',
        'status',
    ];

    protected $casts = [
        'balance_ugx' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'accrued_interest_ugx' => 'decimal:2',
        'minimum_balance_ugx' => 'decimal:2',
        'maturity_date' => 'datetime',
        'allow_early_withdrawal' => 'boolean',
    ];

    /**
     * Accessor for balance (alias for balance_ugx for backward compatibility)
     */
    public function getBalanceAttribute(): float
    {
        return (float) ($this->balance_ugx ?? 0);
    }

    /**
     * Mutator for balance (alias for balance_ugx for backward compatibility)
     */
    public function setBalanceAttribute($value): void
    {
        $this->attributes['balance_ugx'] = $value;
    }

    /**
     * Get the member that owns the account
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    /**
     * Get transactions for this account
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoTransaction::class, 'account_id');
    }

    /**
     * Check if account is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get formatted balance
     */
    public function getFormattedBalanceAttribute(): string
    {
        return 'UGX ' . number_format($this->balance, 2);
    }

    /**
     * Get account type name
     */
    public function getTypeNameAttribute(): string
    {
        return match($this->account_type) {
            'savings' => 'Savings Account',
            'shares' => 'Share Capital',
            'fixed_deposit' => 'Fixed Deposit',
            default => 'Unknown Account Type'
        };
    }
}
