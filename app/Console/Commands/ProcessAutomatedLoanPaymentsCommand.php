<?php

namespace App\Console\Commands;

use App\Services\CrossModuleRevenueService;
use App\Services\CrossModuleNotificationService;
use App\Events\CrossModuleEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessAutomatedLoanPaymentsCommand extends Command
{
    protected $signature = 'loans:process-automated-payments
                           {--dry-run : Run without making actual payments}
                           {--user= : Process only for specific user ID}
                           {--min-revenue= : Minimum revenue threshold (default from config)}';

    protected $description = 'Process automated loan payments from artist revenue earnings';

    protected CrossModuleRevenueService $revenueService;
    protected CrossModuleNotificationService $notificationService;

    public function __construct(
        CrossModuleRevenueService $revenueService,
        CrossModuleNotificationService $notificationService
    ) {
        parent::__construct();
        $this->revenueService = $revenueService;
        $this->notificationService = $notificationService;
    }

    public function handle(): int
    {
        $this->info('🏦 Starting automated loan payment processing...');

        $dryRun = $this->option('dry-run');
        $specificUserId = $this->option('user');
        $minRevenue = $this->option('min-revenue');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No actual payments will be processed');
        }

        try {
            // Get eligible users
            $eligibleUsers = $this->getEligibleUsers($specificUserId, $minRevenue);

            if ($eligibleUsers->isEmpty()) {
                $this->info('ℹ️  No users eligible for automated loan payments');
                return 0;
            }

            $this->info("👥 Found {$eligibleUsers->count()} eligible users");

            $processed = 0;
            $successful = 0;
            $failed = 0;
            $totalAmount = 0;

            foreach ($eligibleUsers as $member) {
                $user = $member->user;
                $loan = $member->activeLoan;

                $this->line("Processing payment for {$user->name} (ID: {$user->id})");

                try {
                    $result = $this->processUserPayment($user, $loan, $dryRun);

                    if ($result['success']) {
                        $successful++;
                        $totalAmount += $result['payment_amount'];
                        $this->info("  ✅ Payment processed: UGX " . number_format($result['payment_amount']));

                        if (!$dryRun) {
                            // Send notification
                            $this->notificationService->sendLoanPaymentNotification(
                                $user,
                                'automated_payment_success',
                                [
                                    'amount' => $result['payment_amount'],
                                    'remaining_balance' => $result['remaining_balance'],
                                    'loan_id' => $loan->id,
                                    'payment_date' => now(),
                                ]
                            );

                            // Fire event for cross-module integration
                            event(new CrossModuleEvent(
                                $user,
                                'sacco',
                                'automated_payment_success',
                                'Automatic Loan Payment Processed',
                                "Your loan payment of UGX " . number_format($result['payment_amount']) . " has been automatically processed.",
                                $result
                            ));
                        }
                    } else {
                        $failed++;
                        $this->error("  ❌ Payment failed: {$result['message']}");

                        if (!$dryRun) {
                            // Send failure notification
                            $this->notificationService->sendLoanPaymentNotification(
                                $user,
                                'automated_payment_failed',
                                [
                                    'reason' => $result['message'],
                                    'loan_id' => $loan->id,
                                    'required_amount' => $loan->monthly_payment,
                                    'current_revenue' => $result['current_revenue'] ?? 0,
                                ]
                            );
                        }
                    }

                    $processed++;

                } catch (\Exception $e) {
                    $failed++;
                    $this->error("  💥 Exception: {$e->getMessage()}");
                    Log::error('Automated loan payment error', [
                        'user_id' => $user->id,
                        'loan_id' => $loan->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                // Add small delay to prevent overwhelming the system
                if (!$dryRun) {
                    usleep(100000); // 0.1 second
                }
            }

            // Summary
            $this->info("\n" . str_repeat('=', 60));
            $this->info("📊 PROCESSING SUMMARY");
            $this->info(str_repeat('=', 60));
            $this->info("Users processed: {$processed}");
            $this->info("Successful payments: {$successful}");
            $this->info("Failed payments: {$failed}");
            $this->info("Total amount processed: UGX " . number_format($totalAmount));

            if ($dryRun) {
                $this->warn("Note: This was a dry run - no actual payments were made");
            } else {
                $this->info("✅ Automated loan payment processing completed successfully");

                // Log summary for audit
                Log::info('Automated loan payments processed', [
                    'processed' => $processed,
                    'successful' => $successful,
                    'failed' => $failed,
                    'total_amount' => $totalAmount,
                    'timestamp' => now(),
                ]);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("💥 Fatal error during automated loan payment processing: {$e->getMessage()}");
            Log::error('Fatal error in automated loan payment processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * Get users eligible for automated loan payments
     */
    protected function getEligibleUsers($specificUserId = null, $minRevenue = null)
    {
        if ($specificUserId) {
            $this->info("🎯 Processing only user ID: {$specificUserId}");

            if (!class_exists(\App\Modules\Sacco\Models\SaccoMember::class)) {
                $this->error('SACCO module not available');
                return collect();
            }

            return \App\Modules\Sacco\Models\SaccoMember::with(['user', 'activeLoan'])
                ->where('user_id', $specificUserId)
                ->whereHas('activeLoan')
                ->where('status', 'active')
                ->get();
        }

        // Get all eligible users
        $eligibleUsers = $this->revenueService->getUsersEligibleForLoanPayments();

        // Apply minimum revenue filter if specified
        if ($minRevenue) {
            $eligibleUsers = $eligibleUsers->filter(function ($member) use ($minRevenue) {
                $revenue = $this->revenueService->calculateTotalUserRevenue($member->user);
                return $revenue['total'] >= $minRevenue;
            });
        }

        return $eligibleUsers;
    }

    /**
     * Process payment for a specific user
     */
    protected function processUserPayment($user, $loan, $dryRun = false): array
    {
        // Calculate current revenue
        $revenue = $this->revenueService->calculateTotalUserRevenue($user);
        $monthlyRevenue = $revenue['total'];
        $monthlyPayment = $loan->monthly_payment;

        $this->line("  💰 Current revenue: UGX " . number_format($monthlyRevenue));
        $this->line("  💳 Required payment: UGX " . number_format($monthlyPayment));

        // Check if sufficient funds
        if ($monthlyRevenue < $monthlyPayment) {
            return [
                'success' => false,
                'message' => 'Insufficient revenue for payment',
                'current_revenue' => $monthlyRevenue,
                'required_payment' => $monthlyPayment,
                'deficit' => $monthlyPayment - $monthlyRevenue,
            ];
        }

        // Check if payment is due
        $lastPayment = $loan->payments()->latest()->first();
        $paymentDue = $this->isPaymentDue($loan, $lastPayment);

        if (!$paymentDue) {
            return [
                'success' => false,
                'message' => 'Payment not due yet',
                'next_due_date' => $this->getNextPaymentDate($loan, $lastPayment),
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'message' => 'DRY RUN - Payment would be processed',
                'payment_amount' => $monthlyPayment,
                'remaining_balance' => max(0, $loan->remaining_balance - $monthlyPayment),
                'dry_run' => true,
            ];
        }

        // Process actual payment
        return $this->revenueService->processAutomatedLoanPayment($user);
    }

    /**
     * Check if payment is due
     */
    protected function isPaymentDue($loan, $lastPayment): bool
    {
        if (!$lastPayment) {
            // No previous payments - check if loan start date + 1 month has passed
            return $loan->created_at->addMonth()->isPast();
        }

        // Check if a month has passed since last payment
        return $lastPayment->created_at->addMonth()->isPast();
    }

    /**
     * Get next payment due date
     */
    protected function getNextPaymentDate($loan, $lastPayment): string
    {
        if (!$lastPayment) {
            return $loan->created_at->addMonth()->toDateString();
        }

        return $lastPayment->created_at->addMonth()->toDateString();
    }

    /**
     * Display help information
     */
    public function getHelp(): string
    {
        return "
This command processes automated loan payments for SACCO members based on their
revenue earnings from the music platform.

The system will:
1. Identify users with active loans and sufficient revenue
2. Calculate their total earnings across all modules (music, podcast, store)
3. Automatically deduct the monthly loan payment from their earnings
4. Update loan balances and send notifications
5. Log all transactions for audit purposes

Options:
  --dry-run                Run without making actual payments (for testing)
  --user=123              Process only specific user ID
  --min-revenue=50000     Set minimum revenue threshold

Examples:
  php artisan loans:process-automated-payments
  php artisan loans:process-automated-payments --dry-run
  php artisan loans:process-automated-payments --user=123
  php artisan loans:process-automated-payments --min-revenue=100000

Safety Features:
- Users must have 20% buffer above payment amount
- Payments are only processed when due
- Comprehensive logging and notifications
- Dry run mode for testing
- Individual user processing for troubleshooting
";
    }
}