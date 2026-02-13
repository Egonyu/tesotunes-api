<?php

namespace App\Services;

use App\Models\User;
use App\Models\Song;
use App\Modules\Podcast\Models\Podcast;
use App\Modules\Store\Models\StoreProduct;
use App\Modules\Sacco\Models\SaccoMember;
use App\Modules\Sacco\Models\Loan;
use Illuminate\Support\Collection;

class CrossModuleRevenueService
{
    /**
     * Calculate total user revenue across all modules
     */
    public function calculateTotalUserRevenue(User $user): array
    {
        $musicRevenue = $this->calculateMusicRevenue($user);
        $podcastRevenue = $this->calculatePodcastRevenue($user);
        $storeRevenue = $this->calculateStoreRevenue($user);

        return [
            'music' => $musicRevenue,
            'podcast' => $podcastRevenue,
            'store' => $storeRevenue,
            'total' => $musicRevenue['total'] + $podcastRevenue['total'] + $storeRevenue['total'],
            'breakdown' => [
                'music_percentage' => $this->calculatePercentage($musicRevenue['total'], $musicRevenue['total'] + $podcastRevenue['total'] + $storeRevenue['total']),
                'podcast_percentage' => $this->calculatePercentage($podcastRevenue['total'], $musicRevenue['total'] + $podcastRevenue['total'] + $storeRevenue['total']),
                'store_percentage' => $this->calculatePercentage($storeRevenue['total'], $musicRevenue['total'] + $podcastRevenue['total'] + $storeRevenue['total']),
            ]
        ];
    }

    /**
     * Calculate music streaming and download revenue
     */
    protected function calculateMusicRevenue(User $user): array
    {
        $songs = $user->songs()->where('songs.status', 'published')->get();

        $streamingRevenue = 0;
        $downloadRevenue = 0;
        $totalStreams = 0;
        $totalDownloads = 0;

        foreach ($songs as $song) {
            // Streaming revenue calculation
            $streams = $song->play_count ?? 0;
            $platformRate = 0.003; // Average per-stream rate
            $artistShare = 0.7; // 70% after platform cut

            $songStreamingRevenue = $streams * $platformRate * $artistShare;
            $streamingRevenue += $songStreamingRevenue;
            $totalStreams += $streams;

            // Download revenue (premium users)
            $downloads = $song->download_count ?? 0;
            $downloadRate = 0.99; // Per download for premium users
            $songDownloadRevenue = $downloads * $downloadRate * $artistShare;
            $downloadRevenue += $songDownloadRevenue;
            $totalDownloads += $downloads;
        }

        return [
            'streaming' => round($streamingRevenue, 2),
            'downloads' => round($downloadRevenue, 2),
            'total' => round($streamingRevenue + $downloadRevenue, 2),
            'stats' => [
                'total_streams' => $totalStreams,
                'total_downloads' => $totalDownloads,
                'songs_count' => $songs->count(),
            ]
        ];
    }

    /**
     * Calculate podcast revenue from subscriptions and sponsorships
     */
    protected function calculatePodcastRevenue(User $user): array
    {
        if (!trait_exists(\App\Modules\Podcast\Traits\HasPodcast::class)) {
            return ['total' => 0, 'subscription' => 0, 'sponsorship' => 0, 'stats' => []];
        }

        $podcasts = $user->ownedPodcasts ?? collect();

        $subscriptionRevenue = 0;
        $sponsorshipRevenue = 0;
        $totalSubscribers = 0;
        $totalEpisodes = 0;

        foreach ($podcasts as $podcast) {
            // Subscription revenue
            $subscribers = $podcast->subscriptions()->where('status', 'active')->count();
            $subscriptionPrice = $podcast->subscription_price ?? 0;
            $podcastSubscriptionRevenue = $subscribers * $subscriptionPrice;
            $subscriptionRevenue += $podcastSubscriptionRevenue;
            $totalSubscribers += $subscribers;

            // Sponsorship revenue
            $sponsors = $podcast->sponsors()->where('status', 'active')->get();
            foreach ($sponsors as $sponsor) {
                if ($sponsor->rate_type === 'per_month') {
                    $sponsorshipRevenue += $sponsor->sponsorship_rate;
                }
            }

            $totalEpisodes += $podcast->total_episodes ?? 0;
        }

        return [
            'subscription' => round($subscriptionRevenue, 2),
            'sponsorship' => round($sponsorshipRevenue, 2),
            'total' => round($subscriptionRevenue + $sponsorshipRevenue, 2),
            'stats' => [
                'total_subscribers' => $totalSubscribers,
                'total_episodes' => $totalEpisodes,
                'podcasts_count' => $podcasts->count(),
            ]
        ];
    }

    /**
     * Calculate store product sales revenue
     */
    protected function calculateStoreRevenue(User $user): array
    {
        if (!trait_exists(\App\Modules\Store\Traits\HasStore::class)) {
            return ['total' => 0, 'sales' => 0, 'commission' => 0, 'stats' => []];
        }

        $store = $user->store;
        
        if (!$store) {
            return ['total' => 0, 'sales' => 0, 'commission' => 0, 'stats' => ['total_sales' => 0, 'products_count' => 0]];
        }

        $storeProducts = $user->storeProducts ?? collect();
        $totalProducts = $storeProducts->count();

        // Calculate revenue from completed orders
        $completedOrders = \App\Modules\Store\Models\Order::where('store_id', $store->id)
            ->where('status', 'completed')
            ->with('items')
            ->get();

        $salesRevenue = 0;
        $totalSales = 0;
        $platformCommission = 0.15; // 15% platform commission

        foreach ($completedOrders as $order) {
            $orderTotal = $order->subtotal ?? 0;
            $salesRevenue += $orderTotal * (1 - $platformCommission);
            $totalSales += $order->items->sum('quantity');
        }

        return [
            'sales' => round($salesRevenue, 2),
            'commission' => round($salesRevenue * $platformCommission, 2),
            'total' => round($salesRevenue, 2),
            'stats' => [
                'total_sales' => $totalSales,
                'products_count' => $totalProducts,
            ]
        ];
    }

    /**
     * Get users eligible for automated loan payments based on revenue
     */
    public function getUsersEligibleForLoanPayments(): Collection
    {
        if (!class_exists(\App\Modules\Sacco\Models\SaccoMember::class)) {
            return collect();
        }

        $saccoMembers = SaccoMember::with(['user', 'activeLoan'])
            ->whereHas('activeLoan')
            ->where('status', 'active')
            ->get();

        return $saccoMembers->filter(function ($member) {
            $revenue = $this->calculateTotalUserRevenue($member->user);
            $loan = $member->activeLoan;

            // Check if user has enough revenue to make monthly payment
            $monthlyRevenue = $revenue['total'];
            $monthlyPayment = $loan->monthly_payment ?? 0;

            return $monthlyRevenue >= ($monthlyPayment * 1.2); // 20% buffer
        });
    }

    /**
     * Process automated loan payment for a user
     */
    public function processAutomatedLoanPayment(User $user): array
    {
        if (!class_exists(\App\Modules\Sacco\Models\SaccoMember::class)) {
            return ['success' => false, 'message' => 'SACCO module not available'];
        }

        $saccoMember = $user->saccoMembership;
        if (!$saccoMember || !$saccoMember->activeLoan) {
            return ['success' => false, 'message' => 'No active loan found'];
        }

        $revenue = $this->calculateTotalUserRevenue($user);
        $loan = $saccoMember->activeLoan;
        $monthlyPayment = $loan->monthly_payment;

        if ($revenue['total'] < $monthlyPayment) {
            return ['success' => false, 'message' => 'Insufficient revenue for payment'];
        }

        // Deduct payment from user's earnings
        $paymentRecord = [
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'amount' => $monthlyPayment,
            'payment_date' => now(),
            'payment_method' => 'automated_revenue_deduction',
            'status' => 'completed',
        ];

        // Update loan balance
        $newBalance = $loan->remaining_balance - $monthlyPayment;
        $loan->update([
            'remaining_balance' => max(0, $newBalance),
            'status' => $newBalance <= 0 ? 'paid' : 'active',
        ]);

        return [
            'success' => true,
            'payment_amount' => $monthlyPayment,
            'remaining_balance' => $newBalance,
            'payment_record' => $paymentRecord,
        ];
    }

    /**
     * Generate cross-module revenue report
     */
    public function generateCrossModuleReport(User $user, string $period = 'monthly'): array
    {
        $revenue = $this->calculateTotalUserRevenue($user);

        $startDate = match ($period) {
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            'quarterly' => now()->subQuarter(),
            'yearly' => now()->subYear(),
            default => now()->subMonth(),
        };

        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'period' => $period,
            'period_start' => $startDate,
            'period_end' => now(),
            'revenue' => $revenue,
            'growth_potential' => $this->calculateGrowthPotential($user),
            'recommendations' => $this->generateRecommendations($revenue),
            'loan_eligibility' => $this->calculateLoanEligibility($user, $revenue),
        ];
    }

    /**
     * Calculate growth potential based on current revenue trends
     */
    protected function calculateGrowthPotential(User $user): array
    {
        // This would analyze historical data to predict growth
        // For now, returning placeholder data
        return [
            'music_growth_rate' => 15, // 15% projected growth
            'podcast_growth_rate' => 25,
            'store_growth_rate' => 10,
            'overall_growth_rate' => 17,
        ];
    }

    /**
     * Generate revenue optimization recommendations
     */
    protected function generateRecommendations(array $revenue): array
    {
        $recommendations = [];

        if ($revenue['music']['total'] > $revenue['podcast']['total'] && $revenue['music']['total'] > $revenue['store']['total']) {
            $recommendations[] = 'Consider expanding your music catalog and promoting your top-performing songs';
        }

        if ($revenue['podcast']['total'] > 0 && $revenue['podcast']['stats']['total_subscribers'] < 100) {
            $recommendations[] = 'Focus on growing your podcast subscriber base to attract more sponsors';
        }

        if ($revenue['store']['total'] == 0) {
            $recommendations[] = 'Consider adding merchandise to your store to diversify revenue streams';
        }

        return $recommendations;
    }

    /**
     * Calculate loan eligibility based on revenue
     */
    protected function calculateLoanEligibility(User $user, array $revenue): array
    {
        $monthlyRevenue = $revenue['total'];
        $maxLoanAmount = $monthlyRevenue * 12 * 0.3; // 30% of annual revenue

        return [
            'eligible' => $monthlyRevenue >= 50000, // Minimum UGX 50,000 monthly
            'max_loan_amount' => round($maxLoanAmount, 2),
            'recommended_payment' => round($monthlyRevenue * 0.25, 2), // 25% of monthly revenue
            'risk_level' => $this->calculateRiskLevel($revenue),
        ];
    }

    /**
     * Calculate risk level for loan assessment
     */
    protected function calculateRiskLevel(array $revenue): string
    {
        $totalRevenue = $revenue['total'];
        $diversification = count(array_filter([$revenue['music']['total'], $revenue['podcast']['total'], $revenue['store']['total']], fn($x) => $x > 0));

        if ($totalRevenue >= 200000 && $diversification >= 2) {
            return 'low';
        } elseif ($totalRevenue >= 100000 && $diversification >= 1) {
            return 'medium';
        } else {
            return 'high';
        }
    }

    /**
     * Helper method to calculate percentage
     */
    protected function calculatePercentage(float $part, float $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0;
    }
}