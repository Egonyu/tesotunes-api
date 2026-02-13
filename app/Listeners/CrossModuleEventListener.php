<?php

namespace App\Listeners;

use App\Events\CrossModuleEvent;
use App\Services\CrossModuleNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class CrossModuleEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    protected CrossModuleNotificationService $notificationService;

    /**
     * Create the event listener.
     */
    public function __construct(CrossModuleNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(CrossModuleEvent $event): void
    {
        // Send notification based on module and type
        match ($event->module) {
            'music' => $this->notificationService->sendMusicNotification(
                $event->user,
                $event->type,
                $event->data
            ),
            'podcast' => $this->notificationService->sendPodcastNotification(
                $event->user,
                $event->type,
                $event->data
            ),
            'store' => $this->notificationService->sendStoreNotification(
                $event->user,
                $event->type,
                $event->data
            ),
            'sacco' => $this->notificationService->sendSaccoNotification(
                $event->user,
                $event->type,
                $event->data
            ),
            default => $this->notificationService->sendToUser(
                $event->user,
                $event->module,
                $event->type,
                $event->title,
                $event->message,
                $event->data
            ),
        };

        // Handle special cross-module logic
        $this->handleCrossModuleLogic($event);
    }

    /**
     * Handle cross-module business logic
     */
    protected function handleCrossModuleLogic(CrossModuleEvent $event): void
    {
        // Check for achievements
        $this->checkAchievements($event);

        // Check for revenue milestones
        $this->checkRevenueMilestones($event);

        // Handle automated loan payments
        $this->handleAutomatedLoanPayments($event);

        // Update user statistics
        $this->updateUserStatistics($event);
    }

    /**
     * Check and award achievements
     */
    protected function checkAchievements(CrossModuleEvent $event): void
    {
        $user = $event->user;

        // First song approved achievement
        if ($event->module === 'music' && $event->following_type === 'song_approved') {
            $approvedSongs = $user->songs()->where('songs.status', 'approved')->count();
            if ($approvedSongs === 1) {
                $this->notificationService->sendAchievementNotification(
                    $user,
                    'first_song_approved'
                );
            }
        }

        // First podcast episode achievement
        if ($event->module === 'podcast' && $event->following_type === 'episode_published') {
            if (method_exists($user, 'ownedPodcasts')) {
                $totalEpisodes = $user->ownedPodcasts->sum('total_episodes');
                if ($totalEpisodes === 1) {
                    $this->notificationService->sendAchievementNotification(
                        $user,
                        'first_podcast_episode'
                    );
                }
            }
        }

        // First store sale achievement
        if ($event->module === 'store' && $event->following_type === 'order_received') {
            if (method_exists($user, 'storeOrders')) {
                $totalOrders = $user->storeOrders()->count();
                if ($totalOrders === 1) {
                    $this->notificationService->sendAchievementNotification(
                        $user,
                        'first_store_sale'
                    );
                }
            }
        }

        // Streaming milestones
        if ($event->module === 'music' && isset($event->data['total_streams'])) {
            $totalStreams = $event->data['total_streams'];

            if ($totalStreams === 100) {
                $this->notificationService->sendAchievementNotification(
                    $user,
                    '100_streams'
                );
            } elseif ($totalStreams === 1000) {
                $this->notificationService->sendAchievementNotification(
                    $user,
                    '1000_streams'
                );
            }
        }

        // Multi-module creator achievement
        $this->checkMultiModuleCreator($user);
    }

    /**
     * Check multi-module creator achievement
     */
    protected function checkMultiModuleCreator($user): void
    {
        $activeModules = 0;

        // Check music module
        if ($user->songs()->where('songs.status', 'published')->exists()) {
            $activeModules++;
        }

        // Check podcast module
        if (method_exists($user, 'ownedPodcasts') && $user->ownedPodcasts()->where('status', 'published')->exists()) {
            $activeModules++;
        }

        // Check store module
        if (method_exists($user, 'storeProducts') && $user->storeProducts()->exists()) {
            $activeModules++;
        }

        if ($activeModules >= 2) {
            // Check if achievement already awarded
            $existingNotification = $user->notifications()
                ->where('following_type', \App\Notifications\CrossModuleNotification::class)
                ->whereJsonContains('data->type', 'multi_module_creator')
                ->exists();

            if (!$existingNotification) {
                $this->notificationService->sendAchievementNotification(
                    $user,
                    'multi_module_creator'
                );
            }
        }
    }

    /**
     * Check revenue milestones
     */
    protected function checkRevenueMilestones(CrossModuleEvent $event): void
    {
        // Only check on revenue-generating events
        $revenueEvents = [
            'royalty_payment',
            'payment_received',
            'sponsor_payment',
            'subscription_payment'
        ];

        if (!in_array($event->type, $revenueEvents)) {
            return;
        }

        $user = $event->user;

        // Calculate total revenue (this would use CrossModuleRevenueService)
        try {
            $revenueService = app(\App\Services\CrossModuleRevenueService::class);
            $totalRevenue = $revenueService->calculateTotalUserRevenue($user);

            // Check for milestone amounts
            $milestones = [100000, 500000, 1000000, 5000000]; // UGX milestones

            foreach ($milestones as $milestone) {
                if ($totalRevenue['total'] >= $milestone) {
                    // Check if milestone notification already sent
                    $existingNotification = $user->notifications()
                        ->where('following_type', \App\Notifications\CrossModuleNotification::class)
                        ->whereJsonContains('data->type', 'milestone_reached')
                        ->whereJsonContains('data->amount', $milestone)
                        ->exists();

                    if (!$existingNotification) {
                        $this->notificationService->sendRevenueMilestoneNotification(
                            $user,
                            $milestone,
                            'total'
                        );
                        break; // Only send one milestone notification at a time
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Could not check revenue milestones: ' . $e->getMessage());
        }
    }

    /**
     * Handle automated loan payments
     */
    protected function handleAutomatedLoanPayments(CrossModuleEvent $event): void
    {
        // Only process on revenue events
        $revenueEvents = ['royalty_payment', 'payment_received'];

        if (!in_array($event->type, $revenueEvents)) {
            return;
        }

        $user = $event->user;

        // Check if user has SACCO membership and active loan
        if (!method_exists($user, 'saccoMembership')) {
            return;
        }

        $saccoMembership = $user->saccoMembership;
        if (!$saccoMembership || !$saccoMembership->activeLoan) {
            return;
        }

        try {
            $revenueService = app(\App\Services\CrossModuleRevenueService::class);
            $result = $revenueService->processAutomatedLoanPayment($user);

            if ($result['success']) {
                $this->notificationService->sendLoanPaymentNotification(
                    $user,
                    'automated_payment_success',
                    [
                        'amount' => $result['payment_amount'],
                        'remaining_balance' => $result['remaining_balance'],
                        'loan_id' => $saccoMembership->activeLoan->id,
                    ]
                );
            } else {
                $this->notificationService->sendLoanPaymentNotification(
                    $user,
                    'automated_payment_failed',
                    [
                        'reason' => $result['message'],
                        'loan_id' => $saccoMembership->activeLoan->id,
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::warning('Could not process automated loan payment: ' . $e->getMessage());
        }
    }

    /**
     * Update user statistics
     */
    protected function updateUserStatistics(CrossModuleEvent $event): void
    {
        $user = $event->user;

        // Update last activity timestamp
        $user->touch();

        // Update module-specific statistics
        match ($event->module) {
            'music' => $this->updateMusicStatistics($user, $event),
            'podcast' => $this->updatePodcastStatistics($user, $event),
            'store' => $this->updateStoreStatistics($user, $event),
            'sacco' => $this->updateSaccoStatistics($user, $event),
            default => null,
        };
    }

    /**
     * Update music statistics
     */
    protected function updateMusicStatistics($user, $event): void
    {
        // This would update cached statistics for performance
        // Implementation depends on specific requirements
    }

    /**
     * Update podcast statistics
     */
    protected function updatePodcastStatistics($user, $event): void
    {
        // This would update podcast-specific statistics
        // Implementation depends on specific requirements
    }

    /**
     * Update store statistics
     */
    protected function updateStoreStatistics($user, $event): void
    {
        // This would update store-specific statistics
        // Implementation depends on specific requirements
    }

    /**
     * Update SACCO statistics
     */
    protected function updateSaccoStatistics($user, $event): void
    {
        // This would update SACCO-specific statistics
        // Implementation depends on specific requirements
    }

    /**
     * Handle failed job
     */
    public function failed(CrossModuleEvent $event, \Throwable $exception): void
    {
        \Log::error('CrossModuleEventListener failed', [
            'user_id' => $event->user->id,
            'module' => $event->module,
            'type' => $event->type,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}