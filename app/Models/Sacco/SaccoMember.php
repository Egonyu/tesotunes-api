<?php

namespace App\Models\Sacco;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SaccoMember extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_RESIGNED = 'resigned';

    public const STATUS_DECEASED = 'deceased';

    protected $fillable = [
        'uuid',
        'user_id',
        'member_number',
        'joined_at',
        'joined_date',
        'approval_date',
        'approved_at',
        'approved_by',
        'status',
        'member_type',
        'membership_type',
        'membership_tier',
        'loan_access_enabled',
        'loan_eligible_at',
        'id_number',
        'id_type',
        'national_id',
        'date_of_birth',
        'phone_number',
        'address',
        'occupation',
        'employer',
        'monthly_income',
        'credit_score',
        'kyc_verified',
        'emergency_contact_name',
        'emergency_contact_phone',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'total_shares',
        'total_savings',
        'total_loans',
        'auto_deposit_enabled',
        'auto_deposit_percentage',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'joined_date' => 'date',
        'approval_date' => 'datetime',
        'approved_at' => 'datetime',
        'loan_access_enabled' => 'boolean',
        'loan_eligible_at' => 'datetime',
        'date_of_birth' => 'date',
        'monthly_income' => 'decimal:2',
        'credit_score' => 'integer',
        'kyc_verified' => 'boolean',
        'total_shares' => 'decimal:2',
        'total_savings' => 'decimal:2',
        'total_loans' => 'decimal:2',
        'auto_deposit_enabled' => 'boolean',
        'auto_deposit_percentage' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => 'active',
        'member_type' => 'regular',
        'membership_type' => 'regular',
        'membership_tier' => 'basic',
        'credit_score' => 400,
        'kyc_verified' => false,
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shares(): HasOne
    {
        return $this->hasOne(SaccoShare::class, 'member_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SaccoAccount::class, 'member_id');
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SaccoSavingsAccount::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'member_id');
    }

    public function activeLoan(): HasOne
    {
        return $this->hasOne(SaccoLoan::class, 'member_id')
            ->whereIn('status', [SaccoLoan::STATUS_DISBURSED, SaccoLoan::STATUS_ACTIVE]);
    }

    public function meetingAttendances(): HasMany
    {
        return $this->hasMany(SaccoMeetingAttendance::class, 'member_id');
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(SaccoMemberDividend::class, 'member_id');
    }

    public function dividendDistributions(): HasMany
    {
        return $this->dividends();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoSavingsTransaction::class, 'member_id');
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(SaccoTransaction::class, 'member_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SaccoContribution::class, 'member_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SaccoGroup::class, 'sacco_group_members', 'member_id', 'group_id')
            ->withPivot('role', 'joined_at');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(SaccoFine::class, 'member_id');
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(SaccoWithdrawalRequest::class, 'member_id');
    }

    public function saccoNotifications(): HasMany
    {
        return $this->hasMany(\App\Models\Notification::class, 'user_id', 'user_id')
            ->where('category', 'sacco');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeResigned($query)
    {
        return $query->where('status', 'resigned');
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeByMemberNumber($query, string $memberNumber)
    {
        return $query->where('member_number', $memberNumber);
    }

    public function savingsAccount(): HasOne
    {
        return $this->hasOne(SaccoAccount::class, 'member_id')->where('account_type', SaccoAccount::TYPE_SAVINGS);
    }

    public function sharesAccount(): HasOne
    {
        return $this->hasOne(SaccoAccount::class, 'member_id')->where('account_type', SaccoAccount::TYPE_SHARES);
    }

    public function checkingAccount(): HasOne
    {
        return $this->hasOne(SaccoAccount::class, 'member_id')->where('account_type', SaccoAccount::TYPE_CHECKING);
    }

    public function boardMemberships(): HasMany
    {
        return $this->hasMany(SaccoBoardMember::class, 'member_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canApplyForLoan(): bool
    {
        return $this->isActive()
            && ($this->kyc_verified || ! config('sacco.membership.kyc_required', true))
            && $this->hasMinimumShares();
    }

    public function hasMinimumShares(): bool
    {
        return $this->getTotalSharesAttribute() >= config('sacco.membership.min_share_capital', 20000);
    }

    public function calculateLoanEligibility(): float
    {
        $savingsEligibility = $this->getTotalSavingsAttribute() * config('sacco.loans.max_loan_to_savings_ratio', 3.0);
        $sharesEligibility = $this->getTotalSharesAttribute() * config('sacco.loan_eligibility_multiplier', 3);

        return max($savingsEligibility, $sharesEligibility);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->isActive();
    }

    public function getTotalSavingsAttribute(): float
    {
        $stored = array_key_exists('total_savings', $this->attributes) && $this->attributes['total_savings'] !== null
            ? (float) $this->attributes['total_savings']
            : 0.0;
        $legacyAccounts = (float) $this->accounts()->where('account_type', SaccoAccount::TYPE_SAVINGS)->sum('balance');
        $savingsAccounts = (float) $this->savingsAccounts()->sum('balance_ugx');

        return max($stored, $legacyAccounts, $savingsAccounts);
    }

    public function getTotalSharesAttribute(): float
    {
        $stored = array_key_exists('total_shares', $this->attributes) && $this->attributes['total_shares'] !== null
            ? (float) $this->attributes['total_shares']
            : 0.0;
        $accountShares = (float) $this->accounts()->where('account_type', SaccoAccount::TYPE_SHARES)->sum('balance');
        $shareCapital = (float) optional($this->shares)->total_value_ugx;

        return max($stored, $accountShares, $shareCapital);
    }

    public function getMaxLoanAmountAttribute(): float
    {
        return $this->calculateLoanEligibility();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($member) {
            if (empty($member->uuid)) {
                $member->uuid = (string) Str::uuid();
            }
            if (empty($member->joined_at)) {
                $member->joined_at = now();
            }
            if (empty($member->joined_date)) {
                $member->joined_date = now()->toDateString();
            }
        });
    }
}
