<?php

namespace App\Services\Sacco;

use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoAccount;
use App\Models\Sacco\SaccoAuditLog;
use App\Models\Sacco\SaccoSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaccoMembershipService
{
    /**
     * Register a new SACCO member
     *
     * @param User $user
     * @param array $data [membership_type, joined_date]
     * @return SaccoMember
     * @throws \Exception
     */
    public function registerMember(User $user, array $data = []): SaccoMember
    {
        // Check if user already has membership
        if ($user->saccoMember) {
            throw new \Exception('User is already a SACCO member');
        }

        return DB::transaction(function () use ($user, $data) {
            // Create member record
            $member = SaccoMember::create([
                'user_id' => $user->id,
                'membership_type' => $data['membership_type'] ?? 'regular',
                'joined_date' => $data['joined_date'] ?? now(),
                'status' => 'pending_approval',
            ]);

            // Create default accounts (shares and savings)
            $this->createDefaultAccounts($member);

            // Audit log
            SaccoAuditLog::log('member_registered', $member, [], $member->toArray());

            return $member->fresh();
        });
    }

    /**
     * Approve a pending SACCO membership
     *
     * @param SaccoMember $member
     * @param User $admin
     * @return void
     * @throws \Exception
     */
    public function approveMember(SaccoMember $member, User $admin): void
    {
        if ($member->status !== 'pending_approval') {
            throw new \Exception('Only pending memberships can be approved');
        }

        DB::transaction(function () use ($member, $admin) {
            $oldValues = $member->toArray();

            $member->update([
                'status' => 'active',
                'approved_at' => now(),
                'approved_by' => $admin->id,
            ]);

            // Activate default accounts
            $member->accounts()->update(['status' => 'active']);

            // Audit log
            SaccoAuditLog::log('member_approved', $member, $oldValues, $member->fresh()->toArray());
        });
    }

    /**
     * Suspend a SACCO member
     *
     * @param SaccoMember $member
     * @param string $reason
     * @return void
     */
    public function suspendMember(SaccoMember $member, string $reason): void
    {
        DB::transaction(function () use ($member, $reason) {
            $oldValues = $member->toArray();

            $member->update(['status' => 'suspended']);

            // Freeze all accounts
            $member->accounts()->update(['status' => 'frozen']);

            // Audit log
            SaccoAuditLog::log(
                'member_suspended',
                $member,
                $oldValues,
                array_merge($member->fresh()->toArray(), ['reason' => $reason])
            );
        });
    }

    /**
     * Reactivate a suspended member
     *
     * @param SaccoMember $member
     * @return void
     * @throws \Exception
     */
    public function reactivateMember(SaccoMember $member): void
    {
        if ($member->status !== 'suspended') {
            throw new \Exception('Only suspended members can be reactivated');
        }

        DB::transaction(function () use ($member) {
            $oldValues = $member->toArray();

            $member->update(['status' => 'active']);

            // Unfreeze accounts
            $member->accounts()->where('status', 'frozen')->update(['status' => 'active']);

            // Audit log
            SaccoAuditLog::log('member_reactivated', $member, $oldValues, $member->fresh()->toArray());
        });
    }

    /**
     * Calculate comprehensive member statistics
     *
     * @param SaccoMember $member
     * @return array
     */
    public function calculateMemberStats(SaccoMember $member): array
    {
        return [
            'member_info' => [
                'member_number' => $member->member_number,
                'membership_type' => $member->membership_type,
                'status' => $member->status,
                'joined_date' => $member->joined_date->format('Y-m-d'),
                'membership_duration_days' => $member->joined_date->diffInDays(now()),
            ],
            'accounts' => [
                'total_balance' => $member->accounts->sum('balance'),
                'available_balance' => $member->accounts->sum('available_balance'),
                'shares_balance' => $member->sharesAccount?->balance ?? 0,
                'savings_balance' => $member->savingsAccount?->balance ?? 0,
                'checking_balance' => $member->checkingAccount?->balance ?? 0,
            ],
            'loans' => [
                'active_loans_count' => $member->loans()->whereIn('status', ['active', 'disbursed'])->count(),
                'total_loans_amount' => $member->loans()->whereIn('status', ['active', 'disbursed'])->sum('balance'),
                'total_loans_paid' => $member->loans()->sum('amount_paid'),
                'completed_loans_count' => $member->loans()->where('status', 'completed')->count(),
            ],
            'transactions' => [
                'total_deposits' => $member->transactions()->where('type', 'deposit')->sum('amount_ugx'),
                'total_withdrawals' => $member->transactions()->where('type', 'withdrawal')->sum('amount_ugx'),
                'recent_transactions_count' => $member->transactions()->where('created_at', '>=', now()->subMonth())->count(),
            ],
            'dividends' => [
                'total_dividends_received' => $member->dividends()->where('status', 'paid')->sum('dividend_amount'),
                'pending_dividends' => $member->dividends()->where('status', 'pending')->sum('dividend_amount'),
            ],
            'eligibility' => [
                'has_minimum_shares' => $member->hasMinimumShares(),
                'max_loan_eligibility' => max($member->total_savings * 3, $member->total_shares * 4),
                'can_apply_for_loan' => $member->status === 'active' && $member->hasMinimumShares(),
            ],
        ];
    }

    /**
     * Create default accounts for new member
     *
     * @param SaccoMember $member
     * @return void
     */
    protected function createDefaultAccounts(SaccoMember $member): void
    {
        $defaultAccounts = [
            [
                'account_type' => 'shares',
                'interest_rate' => SaccoSettings::getValue('shares_dividend_rate', 10),
                'status' => 'active', // Shares account auto-active
            ],
            [
                'account_type' => 'savings',
                'interest_rate' => SaccoSettings::getValue('savings_interest_rate', 5),
                'status' => 'pending', // Pending until membership approved
            ],
        ];

        foreach ($defaultAccounts as $accountData) {
            SaccoAccount::create(array_merge([
                'member_id' => $member->id,
            ], $accountData));
        }
    }

    /**
     * Get membership statistics summary
     *
     * @return array
     */
    public function getMembershipSummary(): array
    {
        return [
            'total_members' => SaccoMember::count(),
            'active_members' => SaccoMember::active()->count(),
            'pending_approval' => SaccoMember::pendingApproval()->count(),
            'suspended_members' => SaccoMember::where('status', 'suspended')->count(),
            'total_shares_capital' => SaccoMember::sum('total_shares'),
            'total_savings' => SaccoMember::sum('total_savings'),
            'total_outstanding_loans' => SaccoMember::sum('total_loans'),
        ];
    }

    /**
     * Validate membership eligibility
     *
     * @param User $user
     * @return array ['eligible' => bool, 'reasons' => array]
     */
    public function checkEligibility(User $user): array
    {
        $reasons = [];
        $eligible = true;

        // Check if already a member
        if ($user->saccoMember) {
            $eligible = false;
            $reasons[] = 'User already has a SACCO membership';
        }

        // Check if user is verified
        if (!$user->email_verified_at) {
            $eligible = false;
            $reasons[] = 'Email verification required';
        }

        // Check minimum account age
        $minimumAccountAgeDays = SaccoSettings::getValue('minimum_account_age_days', 30);
        if ($user->created_at->diffInDays(now()) < $minimumAccountAgeDays) {
            $eligible = false;
            $reasons[] = "Account must be at least {$minimumAccountAgeDays} days old";
        }

        // Check if user is an artist (optional requirement)
        $artistsOnly = SaccoSettings::getValue('artists_only_membership', false);
        if ($artistsOnly && !$user->artist) {
            $eligible = false;
            $reasons[] = 'SACCO membership currently limited to verified artists';
        }

        return [
            'eligible' => $eligible,
            'reasons' => $eligible ? ['All eligibility requirements met'] : $reasons,
        ];
    }

    /**
     * Auto-create SACCO membership for users (tiered approach)
     * Phase 2: Tiered auto-enrollment implementation
     *
     * @param User $user
     * @return SaccoMember|null
     */
    public function autoCreateMembership(User $user): ?SaccoMember
    {
        // Check if user already has membership
        if ($user->saccoMember) {
            return $user->saccoMember;
        }

        // Check if auto-enrollment is enabled
        if (!config('sacco.auto_enrollment.enabled', true)) {
            return null;
        }

        // Determine membership tier based on user type
        $membershipType = $this->determineMembershipTier($user);
        $autoApprove = $this->shouldAutoApprove($user);

        try {
            return DB::transaction(function () use ($user, $membershipType, $autoApprove) {
                // Create member record
                $member = SaccoMember::create([
                    'user_id' => $user->id,
                    'member_number' => $this->generateMembershipNumber(),
                    'membership_type' => $membershipType,
                    'joined_date' => now(),
                    'status' => $autoApprove ? 'active' : 'pending_approval',
                    'approved_at' => $autoApprove ? now() : null,
                    'approved_by' => $autoApprove ? 1 : null, // System auto-approval
                ]);

                // Create default accounts
                $this->createDefaultAccounts($member);

                // If auto-approved, activate accounts
                if ($autoApprove) {
                    $member->accounts()->update(['status' => 'active']);
                }

                // Audit log
                SaccoAuditLog::log('member_auto_enrolled', $member, [], [
                    'member_id' => $member->id,
                    'membership_type' => $membershipType,
                    'auto_approved' => $autoApprove,
                ]);

                return $member->fresh();
            });
        } catch (\Exception $e) {
            \Log::error('SACCO auto-enrollment failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Determine membership tier based on user characteristics
     * Artists get 'associate' tier, regular users get 'regular'
     *
     * @param User $user
     * @return string
     */
    protected function determineMembershipTier(User $user): string
    {
        // Artists automatically get associate membership (higher tier)
        if ($user->hasRole('artist') || $user->artist) {
            return 'associate';
        }

        // Premium subscribers get associate tier
        if ($user->subscription_tier === 'premium' && $user->subscription_expires_at > now()) {
            return 'associate';
        }

        // Default to regular membership
        return 'regular';
    }

    /**
     * Determine if user should be auto-approved for SACCO membership
     * Phase 2: Smart auto-approval logic
     *
     * @param User $user
     * @return bool
     */
    protected function shouldAutoApprove(User $user): bool
    {
        // Artists with verified accounts get auto-approval
        if ($user->hasRole('artist') && $user->artist && $user->artist->verification_status === 'verified') {
            return true;
        }

        // Users with active subscriptions get auto-approval
        if ($user->subscription_tier === 'premium' && $user->subscription_expires_at > now()) {
            return true;
        }

        // Users with account age > 90 days and email verified
        if ($user->email_verified_at && $user->created_at->diffInDays(now()) >= 90) {
            return true;
        }

        // Default: require manual approval
        return config('sacco.auto_enrollment.auto_approve_all', false);
    }

    /**
     * Generate unique membership number
     * Format: SAC-YYYY-XXXXX (e.g., SAC-2025-00123)
     *
     * @return string
     */
    protected function generateMembershipNumber(): string
    {
        $year = now()->format('Y');
        $lastMember = SaccoMember::whereYear('created_at', now()->year)
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastMember ? (int) substr($lastMember->member_number, -5) + 1 : 1;

        return sprintf('SAC-%s-%05d', $year, $sequence);
    }
}
