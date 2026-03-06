<?php

namespace App\Console\Commands;

use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendExpiryReminders extends Command
{
    protected $signature = 'subscriptions:send-expiry-reminders
                            {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send expiry reminders to users whose subscriptions expire in 7, 3, or 1 day(s)';

    protected int $sent = 0;

    protected int $skipped = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info('Sending subscription expiry reminders...'.($dryRun ? ' [DRY RUN]' : ''));

        // Reminder windows: 7 days, 3 days, 1 day before expiry
        $windows = [7, 3, 1];

        foreach ($windows as $days) {
            $this->processWindow($days, $dryRun);
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Reminders Sent', $this->sent],
                ['Skipped (already notified)', $this->skipped],
            ]
        );

        Log::info('subscriptions:send-expiry-reminders completed', [
            'sent' => $this->sent,
            'skipped' => $this->skipped,
        ]);

        return self::SUCCESS;
    }

    protected function processWindow(int $days, bool $dryRun): void
    {
        $this->info("Checking {$days}-day window...");

        // Find subscriptions expiring within this window
        // Must expire between now and now+$days, but NOT already past
        $windowStart = now()->startOfDay();
        $windowEnd = now()->addDays($days)->endOfDay();

        // Only match subscriptions expiring on the exact day boundary
        // e.g., for 7-day: expiring between 6.5 and 7.5 days from now
        $targetStart = now()->addDays($days - 1)->startOfDay();
        $targetEnd = now()->addDays($days)->endOfDay();

        $subscriptions = UserSubscription::with(['user', 'subscriptionPlan'])
            ->where('status', 'active')
            ->whereBetween('expires_at', [$targetStart, $targetEnd])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->line("  No subscriptions expiring in ~{$days} day(s).");

            return;
        }

        foreach ($subscriptions as $subscription) {
            if (! $subscription->user || ! $subscription->subscriptionPlan) {
                $this->skipped++;

                continue;
            }

            // Check if we already sent a reminder for this window
            $reminderKey = "expiry_reminder_{$days}d";
            $metadata = $subscription->metadata ?? [];

            if (! empty($metadata[$reminderKey])) {
                $this->skipped++;

                continue;
            }

            $user = $subscription->user;
            $plan = $subscription->subscriptionPlan;
            $daysRemaining = $subscription->daysUntilExpiry();

            $this->line("  → {$user->display_name} ({$plan->name}) — {$daysRemaining} day(s) left");

            if ($dryRun) {
                $this->sent++;

                continue;
            }

            // Send notification
            $user->notify(
                new SubscriptionNotification(
                    SubscriptionNotification::EXPIRING_SOON,
                    $plan->name,
                    [
                        'days_remaining' => $daysRemaining ?: $days,
                        'expires_at' => $subscription->expires_at->toDateTimeString(),
                        'auto_renew' => $subscription->auto_renew,
                    ]
                )
            );

            // Mark as sent in metadata to prevent duplicate reminders
            $subscription->update([
                'metadata' => array_merge($metadata, [
                    $reminderKey => now()->toIso8601String(),
                ]),
            ]);

            $this->sent++;
        }
    }
}
