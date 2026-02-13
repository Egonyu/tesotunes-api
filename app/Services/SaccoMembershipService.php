<?php

namespace App\Services;

use App\Models\User;
use App\Modules\Sacco\Models\SaccoMember;
use App\Modules\Sacco\Models\SaccoAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaccoMembershipService
{
    /**
     * Auto-create SACCO membership with tiered system (Phase 2)
     */
    public function autoCreateMembership(User $user): ?SaccoMember
    {
        // Check if SACCO module is enabled
        if (!config('sacco.enabled', false)) {
            return null;
        }

        // Check if user already has membership
        if ($user->saccoMember()->exists()) {
            return $user->saccoMember;
        }

        // Check basic eligibility
        if (!$user->email_verified_at) {
            return null;
        }

        return DB::transaction(function () use ($user) {
            // Generate membership number
            $membershipNumber = $this->generateMembershipNumber();

            // Determine member type and tier
            $memberType = $this->determineMemberType($user);
            $memberTier = $this->determineMemberTier($user);

            // Create SACCO member with tiered system
            $member = SaccoMember::create([
                'user_id' => $user->id,
                'member_number' => $membershipNumber,
                'membership_type' => $memberType,  // regular, associate, or honorary
                'membership_tier' => $memberTier,  // basic, artist, or premium
                'status' => 'active',  // Auto-approve
                'joined_date' => now(),
                'approved_at' => now(),
                'approved_by' => null,  // System auto-approval
                'loan_access_enabled' => false,  // Enable after 3 months
                'loan_eligible_at' => now()->addMonths(3),  // Eligible in 3 months
                'total_shares' => 0,
                'total_savings' => 0,
                'total_loans' => 0,
            ]);

            // Create default accounts
            $this->createDefaultAccounts($member);

            return $member;
        });
    }

    /**
     * Determine membership tier (Phase 2 - Tiered System)
     * Returns: 'basic', 'artist', or 'premium'
     */
    private function determineMemberTier(User $user): string
    {
        // Verified artists get artist tier immediately
        if ($user->isArtist() && $user->artist?->is_verified) {
            return 'artist';
        }

        // All others start with basic tier
        return 'basic';
    }

    /**
     * Upgrade user to artist tier (Phase 3 - Artist Earnings Integration)
     */
    public function upgradeToArtistTier(User $user): ?SaccoMember
    {
        $member = $user->saccoMember;
        
        if (!$member) {
            // Create membership first
            $member = $this->autoCreateMembership($user);
        }

        if ($member && $member->membership_tier !== 'artist') {
            $member->update([
                'membership_tier' => 'artist',
                'auto_deposit_enabled' => false,  // User can opt-in
                'auto_deposit_percentage' => 50.00,  // Default 50%
            ]);
        }

        return $member;
    }

    /**
     * Upgrade to premium tier (Phase 2 - Loan eligibility)
     */
    public function upgradeToPremiumTier(User $user): ?SaccoMember
    {
        $member = $user->saccoMember;
        
        if (!$member) {
            return null;
        }

        // Check eligibility: 3 months membership + UGX 50,000 balance
        $memberFor3Months = $member->joined_date->addMonths(3)->isPast();
        $hasMinimumBalance = $member->total_savings >= 50000;

        if ($memberFor3Months && $hasMinimumBalance) {
            $member->update([
                'membership_tier' => 'premium',
                'loan_access_enabled' => true,
                'loan_eligible_at' => now(),
            ]);
        }

        return $member;
    }

    /**
     * Generate unique membership number
     */
    private function generateMembershipNumber(): string
    {
        do {
            $number = 'SM' . date('Y') . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (SaccoMember::where('member_number', $number)->exists());

        return $number;
    }

    /**
     * Determine member type based on user role
     * Returns: 'regular', 'associate', or 'honorary' (matches database ENUM)
     */
    private function determineMemberType(User $user): string
    {
        // Artists get associate membership
        if ($user->hasRole('artist') || $user->role === 'artist') {
            return 'associate';
        }

        // Admins/Special users get honorary membership
        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            return 'honorary';
        }

        // Default: regular membership for standard users
        return 'regular';
    }

    /**
     * Create default accounts for new member
     */
    private function createDefaultAccounts(SaccoMember $member): void
    {
        $accountTypes = [
            [
                'type' => 'savings',
                'name' => 'Savings Account',
                'interest_rate' => 5.0,
            ],
            [
                'type' => 'shares',
                'name' => 'Share Capital Account',
                'interest_rate' => 8.0,
            ],
        ];

        foreach ($accountTypes as $accountData) {
            SaccoAccount::create([
                'member_id' => $member->id,
                'account_number' => $this->generateAccountNumber($member, $accountData['type']),
                'account_type' => $accountData['type'],
                'balance' => 0,
                'available_balance' => 0,
                'interest_rate' => $accountData['interest_rate'],
                'status' => 'active',
                'opened_at' => now(),
            ]);
        }
    }

    /**
     * Generate unique account number
     */
    private function generateAccountNumber(SaccoMember $member, string $type): string
    {
        $prefix = match($type) {
            'savings' => 'SAV',
            'shares' => 'SHR',
            'loan' => 'LON',
            default => 'ACC',
        };

        return $prefix . str_pad($member->id, 6, '0', STR_PAD_LEFT) . rand(100, 999);
    }

    /**
     * Ensure user has SACCO membership (create if doesn't exist)
     */
    public function ensureMembership(User $user): ?SaccoMember
    {
        if ($user->saccoMember()->exists()) {
            return $user->saccoMember;
        }

        return $this->autoCreateMembership($user);
    }
}
