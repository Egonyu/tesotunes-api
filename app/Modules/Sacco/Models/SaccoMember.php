<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\User;

class SaccoMember extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Boot method to auto-generate uuid on creating and handle cascade deletes
     */
    protected static function booted(): void
    {
        static::creating(function (SaccoMember $member) {
            if (empty($member->uuid)) {
                $member->uuid = (string) Str::uuid();
            }
        });

        // Cascade delete related records when member is deleted (soft or force)
        static::deleting(function (SaccoMember $member) {
            // Delete shares (which will cascade to share transactions)
            $member->shares()->delete();
            
            // Delete savings accounts (which will cascade to savings transactions)
            $member->savingsAccounts()->each(function ($account) {
                $account->delete();
            });
            
            // Delete loans (which will cascade to guarantors and repayments)
            $member->loans()->each(function ($loan) {
                $loan->delete();
            });
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\SaccoMemberFactory::new();
    }

    /**
     * Fillable fields - matches actual sacco_members table schema:
     * uuid, user_id, member_number, joined_at, status, member_type,
     * id_number, id_type, date_of_birth, emergency_contact_name, emergency_contact_phone
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'member_number',
        'member_type',     // DB column: enum('regular','premium','lifetime')
        'status',          // DB column: enum('active','suspended','resigned','expelled')
        'joined_at',       // DB column: datetime NOT NULL
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

    /**
     * Get the user that owns the SACCO membership
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the member's shares record
     */
    public function shares(): HasOne
    {
        return $this->hasOne(SaccoShare::class, 'member_id');
    }

    /**
     * Get the savings accounts for the member
     */
    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SaccoSavingsAccount::class, 'member_id');
    }

    /**
     * Get the accounts for the member (legacy alias)
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(SaccoAccount::class, 'member_id');
    }

    /**
     * Get the loans for the member
     */
    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'member_id');
    }

    /**
     * Get the active loan for the member
     */
    public function activeLoan()
    {
        return $this->hasOne(SaccoLoan::class, 'member_id')->where('status', 'active');
    }

    /**
     * Get poll votes for the member
     */
    public function pollVotes(): HasMany
    {
        return $this->hasMany(SaccoPollVote::class, 'member_id');
    }

    /**
     * Get meeting attendances for the member
     */
    public function meetingAttendances(): HasMany
    {
        return $this->hasMany(SaccoMeetingAttendance::class, 'member_id');
    }

    /**
     * Get the transactions for the member
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoTransaction::class, 'member_id');
    }

    /**
     * Get dividend distributions for the member
     */
    public function dividendDistributions(): HasMany
    {
        return $this->hasMany(SaccoDividendDistribution::class, 'member_id');
    }

    /**
     * Check if member is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if member can apply for loans
     */
    public function canApplyForLoan(): bool
    {
        return $this->isActive() 
            && $this->kyc_verified 
            && $this->credit_score >= config('sacco.loans.min_credit_score', 400);
    }

    /**
     * Get total savings balance
     */
    public function getTotalSavingsAttribute(): float
    {
        return $this->accounts()->where('account_type', 'savings')->sum('balance_ugx');
    }

    /**
     * Get total shares balance
     */
    public function getTotalSharesAttribute(): float
    {
        return $this->accounts()->where('account_type', 'shares')->sum('balance_ugx');
    }

    /**
     * Get maximum loan amount eligible
     */
    public function getMaxLoanAmountAttribute(): float
    {
        $savingsBalance = $this->total_savings;
        $ratio = config('sacco.loans.max_loan_to_savings_ratio', 3.0);
        return $savingsBalance * $ratio;
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

    public function scopeExpelled($query)
    {
        return $query->where('status', 'expelled');
    }

    public function scopeByMemberNumber($query, string $memberNumber)
    {
        return $query->where('member_number', $memberNumber);
    }
}
