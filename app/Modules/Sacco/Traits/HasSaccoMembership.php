<?php

namespace App\Modules\Sacco\Traits;

use App\Models\SaccoMember;
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
        if (!config('sacco.enabled', false)) {
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
        if (!config('sacco.enabled', false)) {
            return false;
        }

        // Must be verified user
        if (!$this->email_verified_at) {
            return false;
        }

        // Must not already be a member
        if ($this->saccoMember()->exists()) {
            return false;
        }

        // Check if user is active
        if (!$this->is_active) {
            return false;
        }

        return true;
    }

    /**
     * Get SACCO membership status
     */
    public function saccoMembershipStatus(): ?string
    {
        if (!config('sacco.enabled', false)) {
            return null;
        }

        return $this->saccoMember?->status;
    }

    /**
     * Check if user has pending SACCO application
     */
    public function hasPendingSaccoApplication(): bool
    {
        if (!config('sacco.enabled', false)) {
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
}
