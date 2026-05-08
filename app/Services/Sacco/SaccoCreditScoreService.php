<?php

namespace App\Services\Sacco;

use App\Models\Sacco\SaccoMember;

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

        return max(300, min(900, $totalScore));
    }

    protected function calculateSavingsScore(SaccoMember $member): int
    {
        $totalSavings = $member->accounts()
            ->whereIn('account_type', ['savings', 'shares'])
            ->sum('balance');

        if ($totalSavings >= 5000000) {
            return 150;
        }
        if ($totalSavings >= 2000000) {
            return 120;
        }
        if ($totalSavings >= 1000000) {
            return 90;
        }
        if ($totalSavings >= 500000) {
            return 60;
        }
        if ($totalSavings >= 100000) {
            return 30;
        }

        return 0;
    }

    protected function calculateRepaymentScore(SaccoMember $member): int
    {
        $loans = $member->loans()->whereIn('status', ['completed', 'active'])->get();

        if ($loans->isEmpty()) {
            return 0;
        }

        $onTimeCount = 0;
        $overdueCount = 0;

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
        if ($totalRepayments === 0) {
            return 50;
        }

        $onTimePercentage = ($onTimeCount / $totalRepayments) * 100;

        if ($onTimePercentage === 100.0) {
            return 200;
        }
        if ($onTimePercentage >= 95) {
            return 180;
        }
        if ($onTimePercentage >= 90) {
            return 150;
        }
        if ($onTimePercentage >= 80) {
            return 120;
        }
        if ($onTimePercentage >= 70) {
            return 90;
        }
        if ($onTimePercentage >= 60) {
            return 60;
        }

        return 30;
    }

    protected function calculateMembershipDurationScore(SaccoMember $member): int
    {
        if (! $member->approval_date) {
            return 0;
        }

        $monthsActive = $member->approval_date->diffInMonths(now());

        if ($monthsActive >= 60) {
            return 100;
        }
        if ($monthsActive >= 36) {
            return 80;
        }
        if ($monthsActive >= 24) {
            return 60;
        }
        if ($monthsActive >= 12) {
            return 40;
        }
        if ($monthsActive >= 6) {
            return 20;
        }

        return 10;
    }

    protected function calculateActivityScore(SaccoMember $member): int
    {
        $recentTransactions = $member->transactions()
            ->where('created_at', '>=', now()->subMonths(3))
            ->where('status', 'completed')
            ->count();

        if ($recentTransactions >= 12) {
            return 50;
        }
        if ($recentTransactions >= 9) {
            return 40;
        }
        if ($recentTransactions >= 6) {
            return 30;
        }
        if ($recentTransactions >= 3) {
            return 20;
        }
        if ($recentTransactions >= 1) {
            return 10;
        }

        return 0;
    }

    protected function calculatePenaltyScore(SaccoMember $member): int
    {
        $penalty = 0;

        $overdueLoans = $member->loans()->where('status', 'overdue')->count();
        $penalty += ($overdueLoans * 50);

        $defaultedLoans = $member->loans()->where('status', 'defaulted')->count();
        $penalty += ($defaultedLoans * 150);

        if ($member->status === 'suspended') {
            $penalty += 100;
        }

        return min(300, $penalty);
    }

    public function getCreditGrade(int $score): string
    {
        if ($score >= 800) {
            return 'Excellent';
        }
        if ($score >= 700) {
            return 'Very Good';
        }
        if ($score >= 600) {
            return 'Good';
        }
        if ($score >= 500) {
            return 'Fair';
        }
        if ($score >= 400) {
            return 'Poor';
        }

        return 'Very Poor';
    }

    public function updateMemberCreditScore(SaccoMember $member): int
    {
        $score = $this->calculateCreditScore($member);
        $member->update(['credit_score' => $score]);

        return $score;
    }

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
