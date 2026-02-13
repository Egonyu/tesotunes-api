<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CrossModuleNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Eloquent\Collection;

class CrossModuleNotificationService
{
    /**
     * Send notification to a specific user
     */
    public function sendToUser(
        User $user,
        string $module,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $actionUrl = null,
        ?string $actionText = null
    ): void {
        $notification = new CrossModuleNotification(
            $module,
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );

        $user->notify($notification);
    }

    /**
     * Send notification to multiple users
     */
    public function sendToUsers(
        Collection $users,
        string $module,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $actionUrl = null,
        ?string $actionText = null
    ): void {
        $notification = new CrossModuleNotification(
            $module,
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );

        Notification::send($users, $notification);
    }

    /**
     * Send music-related notifications
     */
    public function sendMusicNotification(
        User $user,
        string $type,
        array $data = []
    ): void {
        [$title, $message, $actionUrl, $actionText] = $this->getMusicNotificationContent($type, $data);

        $this->sendToUser(
            $user,
            'music',
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );
    }

    /**
     * Send podcast-related notifications
     */
    public function sendPodcastNotification(
        User $user,
        string $type,
        array $data = []
    ): void {
        [$title, $message, $actionUrl, $actionText] = $this->getPodcastNotificationContent($type, $data);

        $this->sendToUser(
            $user,
            'podcast',
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );
    }

    /**
     * Send store-related notifications
     */
    public function sendStoreNotification(
        User $user,
        string $type,
        array $data = []
    ): void {
        [$title, $message, $actionUrl, $actionText] = $this->getStoreNotificationContent($type, $data);

        $this->sendToUser(
            $user,
            'store',
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );
    }

    /**
     * Send SACCO-related notifications
     */
    public function sendSaccoNotification(
        User $user,
        string $type,
        array $data = []
    ): void {
        [$title, $message, $actionUrl, $actionText] = $this->getSaccoNotificationContent($type, $data);

        $this->sendToUser(
            $user,
            'sacco',
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );
    }

    /**
     * Send revenue milestone notifications
     */
    public function sendRevenueMilestoneNotification(
        User $user,
        float $amount,
        string $period = 'monthly'
    ): void {
        $title = 'Revenue Milestone Reached!';
        $message = "Congratulations! You've earned UGX " . number_format($amount) . " this {$period}.";

        $this->sendToUser(
            $user,
            'revenue',
            'milestone_reached',
            $title,
            $message,
            ['amount' => $amount, 'period' => $period],
            route('frontend.artist.dashboard'),
            'View Dashboard'
        );
    }

    /**
     * Send cross-module achievement notifications
     */
    public function sendAchievementNotification(
        User $user,
        string $achievement,
        array $data = []
    ): void {
        [$title, $message] = $this->getAchievementContent($achievement, $data);

        $this->sendToUser(
            $user,
            'achievement',
            $achievement,
            $title,
            $message,
            $data,
            route('frontend.artist.dashboard'),
            'View Dashboard'
        );
    }

    /**
     * Send loan payment automation notifications
     */
    public function sendLoanPaymentNotification(
        User $user,
        string $type,
        array $data = []
    ): void {
        [$title, $message, $actionUrl, $actionText] = $this->getLoanPaymentNotificationContent($type, $data);

        $this->sendToUser(
            $user,
            'sacco',
            $type,
            $title,
            $message,
            $data,
            $actionUrl,
            $actionText
        );
    }

    /**
     * Send bulk notifications to all artists
     */
    public function sendToAllArtists(
        string $module,
        string $type,
        string $title,
        string $message,
        array $data = []
    ): void {
        $artists = User::whereHas('roles', function ($query) {
            $query->where('name', 'artist');
        })->get();

        $this->sendToUsers($artists, $module, $type, $title, $message, $data);
    }

    /**
     * Get music notification content
     */
    protected function getMusicNotificationContent(string $type, array $data): array
    {
        return match ($type) {
            'song_approved' => [
                'Song Approved!',
                "Your song '{$data['song_title']}' has been approved for distribution.",
                route('frontend.artist.music.show', $data['song_id']),
                'View Song'
            ],
            'song_rejected' => [
                'Song Needs Attention',
                "Your song '{$data['song_title']}' was rejected: {$data['reason']}",
                route('frontend.artist.music.edit', $data['song_id']),
                'Edit Song'
            ],
            'distribution_live' => [
                'Your Music is Live!',
                "'{$data['song_title']}' is now available on {$data['platform']}!",
                route('frontend.artist.music.show', $data['song_id']),
                'View Details'
            ],
            'royalty_payment' => [
                'Royalty Payment Received',
                "You've received UGX " . number_format($data['amount']) . " in royalties.",
                route('frontend.artist.rights.payouts'),
                'View Payouts'
            ],
            'copyright_claim' => [
                'Copyright Claim Alert',
                "A copyright claim has been filed against '{$data['song_title']}'.",
                route('frontend.artist.rights.show', $data['song_id']),
                'Resolve Claim'
            ],
            default => ['Music Update', 'You have a new music-related update.', null, null]
        };
    }

    /**
     * Get podcast notification content
     */
    protected function getPodcastNotificationContent(string $type, array $data): array
    {
        return match ($type) {
            'podcast_approved' => [
                'Podcast Approved!',
                "Your podcast '{$data['podcast_title']}' has been approved.",
                route('frontend.podcasts.show', $data['podcast_id']),
                'View Podcast'
            ],
            'episode_published' => [
                'Episode Published!',
                "Your episode '{$data['episode_title']}' is now live.",
                route('frontend.podcasts.episodes.show', [$data['podcast_id'], $data['episode_id']]),
                'View Episode'
            ],
            'new_subscriber' => [
                'New Subscriber!',
                "You have a new subscriber to '{$data['podcast_title']}'.",
                route('frontend.podcasts.show', $data['podcast_id']),
                'View Podcast'
            ],
            'sponsor_inquiry' => [
                'Sponsorship Inquiry',
                "You have a new sponsorship inquiry from {$data['company_name']}.",
                route('frontend.podcasts.sponsors.show', $data['inquiry_id']),
                'View Inquiry'
            ],
            default => ['Podcast Update', 'You have a new podcast-related update.', null, null]
        };
    }

    /**
     * Get store notification content
     */
    protected function getStoreNotificationContent(string $type, array $data): array
    {
        return match ($type) {
            'product_approved' => [
                'Product Approved!',
                "Your product '{$data['product_name']}' has been approved for sale.",
                route('frontend.store.products.show', $data['product_id']),
                'View Product'
            ],
            'order_received' => [
                'New Order!',
                "You have a new order for '{$data['product_name']}'.",
                route('frontend.store.orders.show', $data['order_id']),
                'View Order'
            ],
            'payment_received' => [
                'Payment Received',
                "Payment of UGX " . number_format($data['amount']) . " has been received.",
                route('frontend.store.orders.show', $data['order_id']),
                'View Order'
            ],
            'low_inventory' => [
                'Low Inventory Alert',
                "'{$data['product_name']}' is running low on stock ({$data['remaining']} left).",
                route('frontend.store.products.edit', $data['product_id']),
                'Update Inventory'
            ],
            default => ['Store Update', 'You have a new store-related update.', null, null]
        };
    }

    /**
     * Get SACCO notification content
     */
    protected function getSaccoNotificationContent(string $type, array $data): array
    {
        return match ($type) {
            'membership_approved' => [
                'SACCO Membership Approved!',
                'Welcome to the SACCO! You can now access loans and financial services.',
                route('frontend.sacco.dashboard'),
                'View Dashboard'
            ],
            'loan_approved' => [
                'Loan Approved!',
                "Your loan application for UGX " . number_format($data['amount']) . " has been approved.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'View Loan'
            ],
            'loan_disbursed' => [
                'Loan Disbursed',
                "UGX " . number_format($data['amount']) . " has been disbursed to your account.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'View Details'
            ],
            'payment_due' => [
                'Loan Payment Due',
                "Your loan payment of UGX " . number_format($data['amount']) . " is due on {$data['due_date']}.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'Make Payment'
            ],
            'automated_payment_success' => [
                'Automatic Payment Processed',
                "UGX " . number_format($data['amount']) . " has been automatically deducted from your earnings.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'View Details'
            ],
            'automated_payment_failed' => [
                'Automatic Payment Failed',
                "Insufficient earnings to cover your loan payment. Please make a manual payment.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'Make Payment'
            ],
            default => ['SACCO Update', 'You have a new SACCO-related update.', null, null]
        };
    }

    /**
     * Get loan payment notification content
     */
    protected function getLoanPaymentNotificationContent(string $type, array $data): array
    {
        return match ($type) {
            'automated_payment_scheduled' => [
                'Automatic Payment Scheduled',
                "Your next loan payment will be automatically deducted from your earnings on {$data['due_date']}.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'View Loan'
            ],
            'insufficient_earnings' => [
                'Insufficient Earnings for Auto-Payment',
                "Your current earnings are insufficient for automatic loan payment. Consider manual payment.",
                route('frontend.sacco.loans.show', $data['loan_id']),
                'Make Payment'
            ],
            default => ['Loan Payment Update', 'You have a new loan payment-related update.', null, null]
        };
    }

    /**
     * Get achievement notification content
     */
    protected function getAchievementContent(string $achievement, array $data): array
    {
        return match ($achievement) {
            'first_song_approved' => [
                'First Song Achievement!',
                'Congratulations on getting your first song approved for distribution!'
            ],
            'first_podcast_episode' => [
                'Podcaster Achievement!',
                'You\'ve published your first podcast episode!'
            ],
            'first_store_sale' => [
                'First Sale Achievement!',
                'Congratulations on your first store sale!'
            ],
            '100_streams' => [
                'Streaming Milestone!',
                'Your music has reached 100 total streams!'
            ],
            '1000_streams' => [
                'Popular Artist!',
                'Amazing! Your music has reached 1,000 total streams!'
            ],
            'multi_module_creator' => [
                'Multi-Platform Creator!',
                'You\'re now active across multiple modules - music, podcasts, and store!'
            ],
            'verified_artist' => [
                'Verified Artist!',
                'Congratulations! Your artist profile has been verified.'
            ],
            default => ['Achievement Unlocked!', 'You\'ve unlocked a new achievement!']
        };
    }

    /**
     * Get user's unread notifications count by module
     */
    public function getUnreadCountByModule(User $user): array
    {
        $notifications = $user->unreadNotifications()
            ->where('following_type', CrossModuleNotification::class)
            ->get();

        $counts = [
            'music' => 0,
            'podcast' => 0,
            'store' => 0,
            'sacco' => 0,
            'achievement' => 0,
            'revenue' => 0,
            'total' => 0,
        ];

        foreach ($notifications as $notification) {
            $module = $notification->data['module'] ?? 'other';
            if (isset($counts[$module])) {
                $counts[$module]++;
            }
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * Mark notifications as read for a specific module
     */
    public function markModuleNotificationsAsRead(User $user, string $module): void
    {
        $user->unreadNotifications()
            ->where('following_type', CrossModuleNotification::class)
            ->whereJsonContains('data->module', $module)
            ->update(['read_at' => now()]);
    }

    /**
     * Get recent notifications for a user with pagination
     */
    public function getRecentNotifications(User $user, int $limit = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $user->notifications()
            ->where('following_type', CrossModuleNotification::class)
            ->orderBy('created_at', 'desc')
            ->paginate($limit);
    }

    /**
     * Send test notification (for debugging)
     */
    public function sendTestNotification(User $user): void
    {
        $this->sendToUser(
            $user,
            'system',
            'test',
            'Test Notification',
            'This is a test notification to verify the cross-module notification system is working.',
            ['test' => true],
            route('frontend.artist.dashboard'),
            'View Dashboard'
        );
    }
}