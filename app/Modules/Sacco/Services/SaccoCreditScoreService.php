<?php

namespace App\Modules\Sacco\Services;

use App\Modules\Sacco\Models\SaccoMember;
use App\Modules\Sacco\Models\SaccoLoan;
use App\Modules\Sacco\Models\SaccoAccount;
use Carbon\Carbon;

class SaccoCreditScoreService
{
    /**
     * Calculate credit score for a member
     * Score Range: 300-900
     */
    public function calculateCreditScore(SaccoMember $member): int
    {
        $baseScore = 500;
        
        $savingsScore = $this->calculateSavingsScore($member);
        $repaymentScore = $this->calculateRepaymentScore($member);
        $membershipScore = $this->calculateMembershipDurationScore($member);
        $activityScore = $this->calculateActivityScore($member);
        $penaltyScore = $this->calculatePenaltyScore($member);
        
        $totalScore = $baseScore + $savingsScore + $repaymentScore + $membershipScore + $activityScore - $penaltyScore;
        
        // Ensure score is within range
        return max(300, min(900, $totalScore));
    }

    /**
     * Calculate savings score component (0-150 points)
     */
    protected function calculateSavingsScore(SaccoMember $member): int
    {
        $totalSavings = $member->accounts()
            ->whereIn('account_type', ['savings', 'shares'])
            ->sum('balance_ugx');
        
        // Score based on savings thresholds
        if ($totalSavings >= 5000000) return 150; // UGX 5M+
        if ($totalSavings >= 2000000) return 120; // UGX 2M+
        if ($totalSavings >= 1000000) return 90;  // UGX 1M+
        if ($totalSavings >= 500000) return 60;   // UGX 500K+
        if ($totalSavings >= 100000) return 30;   // UGX 100K+
        
        return 0;
    }

    /**
     * Calculate repayment history score (0-200 points)
     */
    protected function calculateRepaymentScore(SaccoMember $member): int
    {
        $loans = $member->loans()->whereIn('status', ['completed', 'active'])->get();
        
        if ($loans->isEmpty()) {
            return 0; // No loan history
        }
        
        $totalLoans = $loans->count();
        $completedLoans = $loans->where('status', 'completed')->count();
        $overdueCount = 0;
        $onTimeCount = 0;
        
        foreach ($loans as $loan) {
            $repayments = $loan->repayments;
            foreach ($repayments as $repayment) {
                if ($repayment->status === 'paid' && $repayment->payment_date) {
                    if ($repayment->payment_date->lte($repayment->due_date)) {
                        $onTimeCount++;
                    } else {
                        $overdueCount++;
                    }
                }
            }
        }
        
        $totalRepayments = $onTimeCount + $overdueCount;
        if ($totalRepayments === 0) return 50;
        
        $onTimePercentage = ($onTimeCount / $totalRepayments) * 100;
        
        // Perfect repayment record
        if ($onTimePercentage === 100) return 200;
        if ($onTimePercentage >= 95) return 180;
        if ($onTimePercentage >= 90) return 150;
        if ($onTimePercentage >= 80) return 120;
        if ($onTimePercentage >= 70) return 90;
        if ($onTimePercentage >= 60) return 60;
        
        return 30;
    }

    /**
     * Calculate membership duration score (0-100 points)
     */
    protected function calculateMembershipDurationScore(SaccoMember $member): int
    {
        if (!$member->approval_date) return 0;
        
        $monthsActive = $member->approval_date->diffInMonths(now());
        
        if ($monthsActive >= 60) return 100; // 5+ years
        if ($monthsActive >= 36) return 80;  // 3+ years
        if ($monthsActive >= 24) return 60;  // 2+ years
        if ($monthsActive >= 12) return 40;  // 1+ year
        if ($monthsActive >= 6) return 20;   // 6+ months
        
        return 10;
    }

    /**
     * Calculate account activity score (0-50 points)
     */
    protected function calculateActivityScore(SaccoMember $member): int
    {
        $recentTransactions = $member->transactions()
            ->where('created_at', '>=', now()->subMonths(3))
            ->where('status', 'completed')
            ->count();
        
        if ($recentTransactions >= 12) return 50; // 4+ per month
        if ($recentTransactions >= 9) return 40;  // 3+ per month
        if ($recentTransactions >= 6) return 30;  // 2+ per month
        if ($recentTransactions >= 3) return 20;  // 1+ per month
        if ($recentTransactions >= 1) return 10;
        
        return 0;
    }

    /**
     * Calculate penalty deductions (0-300 points)
     */
    protected function calculatePenaltyScore(SaccoMember $member): int
    {
        $penalty = 0;
        
        // Deduct for current overdue loans
        $overdueLoans = $member->loans()
            ->where('status', 'overdue')
            ->count();
        $penalty += ($overdueLoans * 50);
        
        // Deduct for defaulted loans
        $defaultedLoans = $member->loans()
            ->where('status', 'defaulted')
            ->count();
        $penalty += ($defaultedLoans * 150);
        
        // Deduct for suspended status
        if ($member->status === 'suspended') {
            $penalty += 100;
        }
        
        return min(300, $penalty);
    }

    /**
     * Get credit score grade
     */
    public function getCreditGrade(int $score): string
    {
        if ($score >= 800) return 'Excellent';
        if ($score >= 700) return 'Very Good';
        if ($score >= 600) return 'Good';
        if ($score >= 500) return 'Fair';
        if ($score >= 400) return 'Poor';
        return 'Very Poor';
    }

    /**
     * Update member credit score
     */
    public function updateMemberCreditScore(SaccoMember $member): int
    {
        $score = $this->calculateCreditScore($member);
        $member->update(['credit_score' => $score]);
        
        return $score;
    }

    /**
     * Batch update all member credit scores
     */
    public function updateAllCreditScores(): int
    {
        $members = SaccoMember::where('status', 'active')->get();
        $updated = 0;
        
        foreach ($members as $member) {
            $this->updateMemberCreditScore($member);
            $updated++;
        }
        
        return $updated;
    }
}
