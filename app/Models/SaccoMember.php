<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoMember extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (SaccoMember $member) {
            if (empty($member->uuid)) {
                $member->uuid = Str::uuid();
            }
        });
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'member_number',
        'member_type',
        'joined_at',
        'status',
        'id_number',
        'id_type',
        'date_of_birth',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'date_of_birth' => 'date',
    ];

    // Membership statuses
    const STATUS_PENDING = 'pending_approval';
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SUSPENDED = 'suspended';

    // Membership types
    const TYPE_REGULAR = 'regular';
    const TYPE_ASSOCIATE = 'associate';
    const TYPE_HONORARY = 'honorary';

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SaccoAccount::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoTransaction::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'member_id');
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(SaccoMemberDividend::class, 'member_id');
    }

    public function boardMemberships(): HasMany
    {
        return $this->hasMany(SaccoBoardMember::class, 'member_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeRegular($query)
    {
        return $query->where('membership_type', self::TYPE_REGULAR);
    }

    /**
     * Business Logic Methods
     */
    public function canApplyForLoan(): bool
    {
        return $this->status === self::STATUS_ACTIVE 
            && $this->total_shares >= config('sacco.minimum_shares_for_loan', 100000);
    }

    public function calculateLoanEligibility(): float
    {
        // Typically 3x shares value
        $multiplier = config('sacco.loan_eligibility_multiplier', 3);
        return $this->total_shares * $multiplier;
    }

    public function isActiveBoard(): bool
    {
        return $this->boardMemberships()
            ->where('is_active', true)
            ->where('term_end_date', '>', now())
            ->exists();
    }

    public function getTotalDividendsEarned(): float
    {
        return $this->dividends()
            ->where('status', 'paid')
            ->sum('dividend_amount');
    }

    public function getActiveLoanBalance(): float
    {
        return $this->loans()
            ->whereIn('status', ['disbursed', 'active'])
            ->sum('balance');
    }

    /**
     * Accessors
     */
    public function getIsEligibleForLoanAttribute(): bool
    {
        return $this->canApplyForLoan();
    }

    public function getAvailableLoanAmountAttribute(): float
    {
        return $this->calculateLoanEligibility();
    }

    public function getNetWorthAttribute(): float
    {
        return $this->total_shares + $this->total_savings - $this->getActiveLoanBalance();
    }
}
