<?php

namespace App\Modules\Sacco\Traits;

use App\Models\Sacco\SaccoMember;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasSaccoMembership
{
    /**
     * Get the SACCO member record
     */
    public function saccoMember(): HasOne
    {
        return $this->hasOne(SaccoMember::class);
    }

    /**
     * Check if user is a SACCO member
     */
    public function isSaccoMember(): bool
    {
        // Module must be enabled
        if (! config('sacco.enabled', false)) {
            return false;
        }

        return $this->saccoMember()->exists()
            && $this->saccoMember->status === 'active';
    }

    /**
     * Check if user can join SACCO
     */
    public function canJoinSacco(): bool
    {
        // Module must be enabled
        if (! config('sacco.enabled', false)) {
            return false;
        }

        // Must be verified user
        if (! $this->email_verified_at) {
            return false;
        }

        // Must not already be a member
        if ($this->saccoMember()->exists()) {
            return false;
        }

        // Check if user is active
        if (! $this->is_active) {
            return false;
        }

        return true;
    }

    /**
     * Get SACCO membership status
     */
    public function saccoMembershipStatus(): ?string
    {
        if (! config('sacco.enabled', false)) {
            return null;
        }

        return $this->saccoMember?->status;
    }

    /**
     * Check if user has pending SACCO application
     */
    public function hasPendingSaccoApplication(): bool
    {
        if (! config('sacco.enabled', false)) {
            return false;
        }

        return $this->saccoMember()->where('status', 'pending')->exists();
    }

    /**
     * Get SACCO member ID
     */
    public function saccoMemberId(): ?int
    {
        return $this->saccoMember?->id;
    }

    /**
     * Scope query to SACCO members only
     */
    public function scopeSaccoMembers($query)
    {
        return $query->whereHas('saccoMember', function ($q) {
            $q->where('status', 'active');
        });
    }

    public function getSaccoAccountsAttribute()
    {
        if (! config('sacco.enabled', false)) {
            return collect();
        }

        return $this->saccoMember?->accounts ?? collect();
    }

    public function getTotalSaccoBalanceAttribute(): float
    {
        if (! config('sacco.enabled', false)) {
            return 0.0;
        }

        return (float) ($this->saccoMember?->total_balance ?? 0);
    }

    public function hasActiveSaccoLoans(): bool
    {
        if (! config('sacco.enabled', false)) {
            return false;
        }

        return $this->saccoMember?->loans()
            ->whereIn('status', ['active', 'disbursed'])
            ->exists() ?? false;
    }

    public function getSaccoEligibilityAttribute(): array
    {
        if (! config('sacco.enabled', false)) {
            return ['eligible' => false, 'reason' => 'SACCO module is disabled'];
        }

        $member = $this->saccoMember;

        if (! $member || $member->status !== 'active') {
            return ['eligible' => false, 'reason' => 'Not an active SACCO member'];
        }

        return [
            'eligible' => true,
            'max_loan_amount' => max(
                $member->total_savings * 3,
                $member->total_shares * 4
            ),
            'current_loans' => $member->active_loans_count,
            'max_loans' => 3,
        ];
    }
}
