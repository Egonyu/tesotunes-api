<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'account_number',
        'account_type',
        'interest_rate',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected $guarded = [
        'balance',
        'available_balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $attributes = [
        'balance' => 0,
        'available_balance' => 0,
        'interest_rate' => 0,
        'status' => 'active',
    ];

    // Relationships
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoTransaction::class, 'account_id');
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

    public function scopeByAccountNumber($query, string $accountNumber)
    {
        return $query->where('account_number', $accountNumber);
    }

    // Accessors
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getHasSufficientBalanceAttribute(): bool
    {
        return $this->available_balance > 0;
    }

    public function getIsSharesAccountAttribute(): bool
    {
        return $this->account_type === 'shares';
    }

    public function getIsSavingsAccountAttribute(): bool
    {
        return $this->account_type === 'savings';
    }

    // Business Logic
    public function canWithdraw(float $amount): bool
    {
        return $this->status === 'active'
            && $this->available_balance >= $amount
            && $amount > 0;
    }

    public function calculatePendingBalance(): float
    {
        return $this->balance - $this->available_balance;
    }

    public function calculateMonthlyInterest(): float
    {
        if ($this->interest_rate <= 0) {
            return 0;
        }

        // Simple interest: Principal * Rate * Time
        // Monthly interest = (Balance * Annual Rate) / 12
        return ($this->balance * $this->interest_rate) / (12 * 100);
    }

    // Auto-generate account number
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            if (empty($account->account_number)) {
                $account->account_number = self::generateAccountNumber($account->account_type);
            }
            
            if (empty($account->opened_at)) {
                $account->opened_at = now();
            }
        });
    }

    protected static function generateAccountNumber(string $accountType): string
    {
        $prefix = match($accountType) {
            'shares' => 'SHR',
            'savings' => 'SAV',
            'checking' => 'CHK',
            'fixed_deposit' => 'FXD',
            default => 'ACC',
        };

        $year = now()->format('Y');
        
        // Get last account for this type
        $lastAccount = self::where('account_number', 'like', "{$prefix}-{$year}-%")
            ->orderBy('account_number', 'desc')
            ->first();

        if ($lastAccount) {
            $lastNumber = (int) substr($lastAccount->account_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('%s-%s-%06d', $prefix, $year, $nextNumber);
    }
}
