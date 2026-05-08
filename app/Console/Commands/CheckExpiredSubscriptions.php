<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use App\Services\Payment\ZengaPayService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:check-expired
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Auto-renew or expire subscriptions that have reached their expiry date';

    protected int $renewed = 0;

    protected int $expired = 0;

    protected int $renewalFailed = 0;

    protected int $skipped = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info('Checking expired subscriptions...'.($dryRun ? ' [DRY RUN]' : ''));

        // Fetch active subscriptions where expires_at is in the past
        $expiredSubscriptions = UserSubscription::with(['user', 'subscriptionPlan'])
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiredSubscriptions->isEmpty()) {
            $this->info('No expired subscriptions found.');

            return self::SUCCESS;
        }

        $this->info("Found {$expiredSubscriptions->count()} expired subscription(s).");

        foreach ($expiredSubscriptions as $subscription) {
            if (! $subscription->user || ! $subscription->subscriptionPlan) {
                $this->warn("Skipping subscription #{$subscription->id} — missing user or plan.");
                $this->skipped++;

                continue;
            }

            if ($subscription->auto_renew) {
                $this->processAutoRenewal($subscription, $dryRun);
            } else {
                $this->processExpiry($subscription, $dryRun);
            }
        }

        // Also expire stale pending_renewal subscriptions (no webhook response within 1 hour)
        $this->expireStalePendingRenewals($dryRun);

        $this->newLine();
        $this->table(
            ['Action', 'Count'],
            [
                ['Renewed', $this->renewed],
                ['Expired', $this->expired],
                ['Renewal Failed → Expired', $this->renewalFailed],
                ['Skipped', $this->skipped],
            ]
        );

        Log::info('subscriptions:check-expired completed', [
            'renewed' => $this->renewed,
            'expired' => $this->expired,
            'renewal_failed' => $this->renewalFailed,
            'skipped' => $this->skipped,
        ]);

        return self::SUCCESS;
    }

    protected function processAutoRenewal(UserSubscription $subscription, bool $dryRun): void
    {
        $user = $subscription->user;
        $plan = $subscription->subscriptionPlan;

        $this->line("  → Auto-renewing #{$subscription->id} ({$user->display_name} — {$plan->name})");

        if ($dryRun) {
            $this->renewed++;

            return;
        }

        // Free plans: just extend, no payment needed
        $amount = $plan->price_local ?: ($plan->price_monthly ?: $plan->price);
        if ($amount <= 0) {
            $this->extendSubscription($subscription, $plan);
            $this->renewed++;

            return;
        }

        // Paid plans: initiate ZengaPay charge
        try {
            $result = $this->initiateRenewalCharge($subscription, $plan, $amount);

            if ($result['success']) {
                // Payment is async — user must approve on phone.
                // Mark subscription as pending_renewal so it stays active during the approval window.
                $subscription->update([
                    'status' => 'pending_renewal',
                    'metadata' => array_merge($subscription->metadata ?? [], [
                        'renewal_payment_ref' => $result['reference'] ?? null,
                        'renewal_initiated_at' => now()->toIso8601String(),
                        'renewal_payment_id' => $result['payment_id'] ?? null,
                    ]),
                ]);

                $this->renewed++;
                $this->info('    ✓ Renewal charge initiated — awaiting user approval');
            } else {
                // Charge initiation failed — expire the subscription
                $this->failRenewal($subscription, $result['message'] ?? 'Charge initiation failed');
            }
        } catch (Exception $e) {
            Log::error('Auto-renewal exception', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->failRenewal($subscription, $e->getMessage());
        }
    }

    protected function initiateRenewalCharge(UserSubscription $subscription, $plan, float $amount): array
    {
        $user = $subscription->user;

        // Get phone number from last subscription payment or user's phone
        $phoneNumber = $this->getPhoneForRenewal($subscription, $user);

        if (! $phoneNumber) {
            return [
                'success' => false,
                'message' => 'No phone number available for renewal charge',
            ];
        }

        DB::beginTransaction();

        try {
            // Create a payment record for the renewal
            $reference = 'RENEW_'.strtoupper(uniqid()).'_'.time();

            $payment = new Payment;
            $payment->fill([
                'user_id' => $user->id,
                'payment_reference' => $reference,
                'transaction_id' => $reference,
                'currency' => $plan->currency ?? 'UGX',
                'payment_method' => 'zengapay',
                'payment_provider' => 'zengapay',
                'phone_number' => $phoneNumber,
                'description' => "Auto-renewal: {$plan->name}",
                'payment_type' => 'subscription',
                'payable_type' => 'App\Models\SubscriptionPlan',
                'payable_id' => $plan->id,
                'metadata' => [
                    'auto_renewal' => true,
                    'subscription_id' => $subscription->id,
                    'plan_slug' => $plan->slug,
                ],
            ]);
            $payment->forceFill([
                'amount' => $amount,
                'status' => 'pending',
            ]);
            $payment->save();

            // Initiate ZengaPay collection
            $chargeResult = app(ZengaPayService::class)->collect(
                $amount,
                $phoneNumber,
                $reference,
                "TesoTunes {$plan->name} renewal",
            );

            if ($chargeResult['success']) {
                $payment->forceFill(['status' => 'processing', 'initiated_at' => now()])->save();
                $payment->update([
                    'provider_transaction_id' => $chargeResult['transaction_id'] ?? null,
                    'transaction_reference' => $chargeResult['reference'] ?? $reference,
                ]);

                DB::commit();

                return [
                    'success' => true,
                    'payment_id' => $payment->id,
                    'reference' => $reference,
                    'transaction_id' => $chargeResult['transaction_id'] ?? null,
                ];
            }

            // Charge failed at gateway level
            $payment->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $chargeResult['message'] ?? 'Gateway rejected charge',
            ])->save();

            DB::commit();

            return [
                'success' => false,
                'message' => $chargeResult['message'] ?? 'Gateway rejected charge',
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function getPhoneForRenewal(UserSubscription $subscription, $user): ?string
    {
        // 1. Check payment metadata on the subscription itself
        $metadata = $subscription->metadata ?? [];
        if (! empty($metadata['phone_number'])) {
            return $metadata['phone_number'];
        }

        // 2. Check the payment that created this subscription
        if ($subscription->payment && $subscription->payment->phone_number) {
            return $subscription->payment->phone_number;
        }

        // 3. Fall back to user's phone number
        return $user->phone_number ?: $user->phone;
    }

    protected function extendSubscription(UserSubscription $subscription, $plan): void
    {
        $subscription->update([
            'expires_at' => now()->addDays($plan->duration_days),
            'extended_at' => now(),
            'extension_reason' => 'auto_renewal',
        ]);

        $subscription->user->notify(
            new SubscriptionNotification(SubscriptionNotification::RENEWED, $plan->name)
        );

        $this->info("    ✓ Extended (free plan) until {$subscription->fresh()->expires_at->toDateString()}");
    }

    protected function failRenewal(UserSubscription $subscription, string $reason): void
    {
        $user = $subscription->user;
        $plan = $subscription->subscriptionPlan;

        // Expire the subscription
        $subscription->update([
            'status' => 'expired',
            'auto_renew' => false,
            'metadata' => array_merge($subscription->metadata ?? [], [
                'renewal_failed_at' => now()->toIso8601String(),
                'renewal_failure_reason' => $reason,
            ]),
        ]);

        // Notify user of failure
        $user->notify(
            new SubscriptionNotification(SubscriptionNotification::PAYMENT_FAILED, $plan->name, [
                'reason' => $reason,
            ])
        );

        $this->renewalFailed++;
        $this->warn("    ✗ Renewal failed — subscription expired ({$reason})");
    }

    protected function processExpiry(UserSubscription $subscription, bool $dryRun): void
    {
        $user = $subscription->user;
        $plan = $subscription->subscriptionPlan;

        $this->line("  → Expiring #{$subscription->id} ({$user->display_name} — {$plan->name})");

        if ($dryRun) {
            $this->expired++;

            return;
        }

        $subscription->update([
            'status' => 'expired',
        ]);

        $user->notify(
            new SubscriptionNotification(SubscriptionNotification::EXPIRED, $plan->name)
        );

        $this->expired++;
        $this->info('    ✓ Expired');
    }

    protected function expireStalePendingRenewals(bool $dryRun): void
    {
        // Subscriptions stuck in pending_renewal for over 1 hour — payment was never confirmed
        $stale = UserSubscription::with(['user', 'subscriptionPlan'])
            ->where('status', 'pending_renewal')
            ->where('expires_at', '<=', now()->subHour())
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $this->warn("Found {$stale->count()} stale pending_renewal subscription(s).");

        foreach ($stale as $subscription) {
            if (! $subscription->user || ! $subscription->subscriptionPlan) {
                $this->skipped++;

                continue;
            }

            $this->line("  → Expiring stale renewal #{$subscription->id} ({$subscription->user->display_name})");

            if ($dryRun) {
                $this->expired++;

                continue;
            }

            $this->failRenewal($subscription, 'Renewal payment not confirmed within grace period');
        }
    }
}
