<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoSavingsAccount extends Model
{
    use HasFactory;

    protected $table = 'sacco_savings_accounts';

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
        'withdrawal_limit_monthly' => 'decimal:2',
        'maturity_date' => 'datetime',
        'allow_early_withdrawal' => 'boolean',
    ];

    protected $attributes = [
        'status' => 'active',
        'balance_ugx' => 0,
        'accrued_interest_ugx' => 0,
        'account_type' => 'regular',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            if (empty($account->uuid)) {
                $account->uuid = (string) Str::uuid();
            }
            if (empty($account->account_number)) {
                $account->account_number = 'SAV'.now()->format('Ymd').rand(10000, 99999);
            }
        });
    }

    // Relationships
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoSavingsTransaction::class, 'account_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('account_type', $type);
    }

    // Helpers
    public function canWithdraw(float $amount): bool
    {
        $availableBalance = $this->balance_ugx - ($this->minimum_balance_ugx ?? 0);

        return $this->status === 'active' && $availableBalance >= $amount && $amount > 0;
    }
}
