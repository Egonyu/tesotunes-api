<?php

namespace App\Jobs;

use App\Services\CrossModuleRevenueService;
use App\Services\CrossModuleNotificationService;
use App\Events\CrossModuleEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMonthlyLoanPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour timeout
    public int $tries = 3;

    protected bool $dryRun;
    protected ?int $specificUserId;

    /**
     * Create a new job instance.
     */
    public function __construct(bool $dryRun = false, ?int $specificUserId = null)
    {
        $this->dryRun = $dryRun;
        $this->specificUserId = $specificUserId;
        $this->onQueue('loan-payments'); // Use dedicated queue
    }

    /**
     * Execute the job.
     */
    public function handle(
        CrossModuleRevenueService $revenueService,
        CrossModuleNotificationService $notificationService
    ): void {
        Log::info('Starting automated loan payment processing job', [
            'dry_run' => $this->dryRun,
            'specific_user_id' => $this->specificUserId,
            'timestamp' => now(),
        ]);

        try {
            $eligibleUsers = $this->getEligibleUsers($revenueService);

            if ($eligibleUsers->isEmpty()) {
                Log::info('No users eligible for automated loan payments');
                return;
            }

            Log::info("Processing automated payments for {$eligibleUsers->count()} users");

            $results = [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_amount' => 0,
                'errors' => [],
            ];

            foreach ($eligibleUsers as $member) {
                $userResult = $this->processUserPayment(
                    $member,
                    $revenueService,
                    $notificationService
                );

                $results['processed']++;

                if ($userResult['success']) {
                    $results['successful']++;
                    $results['total_amount'] += $userResult['payment_amount'];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'user_id' => $member->user_id,
                        'error' => $userResult['message'],
                    ];
                }

                // Small delay between payments
                if (!$this->dryRun) {
                    usleep(100000); // 0.1 second
                }
            }

            // Log summary
            Log::info('Automated loan payment processing completed', $results);

            // Send admin notification about batch results
            $this->sendBatchSummaryNotification($results, $notificationService);

        } catch (\Exception $e) {
            Log::error('Error in automated loan payment processing job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Get users eligible for automated payments
     */
    protected function getEligibleUsers(CrossModuleRevenueService $revenueService)
    {
        if ($this->specificUserId) {
            if (!class_exists(\App\Modules\Sacco\Models\SaccoMember::class)) {
                return collect();
            }

            return \App\Modules\Sacco\Models\SaccoMember::with(['user', 'activeLoan'])
                ->where('user_id', $this->specificUserId)
                ->whereHas('activeLoan')
                ->where('status', 'active')
                ->get();
        }

        return $revenueService->getUsersEligibleForLoanPayments();
    }

    /**
     * Process payment for individual user
     */
    protected function processUserPayment(
        $member,
        CrossModuleRevenueService $revenueService,
        CrossModuleNotificationService $notificationService
    ): array {
        $user = $member->user;
        $loan = $member->activeLoan;

        try {
            // Check if payment is due
            if (!$this->isPaymentDue($loan)) {
                return [
                    'success' => false,
                    'message' => 'Payment not due yet',
                    'payment_amount' => 0,
                ];
            }

            if ($this->dryRun) {
                $revenue = $revenueService->calculateTotalUserRevenue($user);
                return [
                    'success' => true,
                    'message' => 'DRY RUN - Would process payment',
                    'payment_amount' => $loan->monthly_payment,
                    'user_revenue' => $revenue['total'],
                ];
            }

            // Process actual payment
            $result = $revenueService->processAutomatedLoanPayment($user);

            if ($result['success']) {
                // Send success notification
                $notificationService->sendLoanPaymentNotification(
                    $user,
                    'automated_payment_success',
                    [
                        'amount' => $result['payment_amount'],
                        'remaining_balance' => $result['remaining_balance'],
                        'loan_id' => $loan->id,
                        'payment_date' => now(),
                        'method' => 'automated_revenue_deduction',
                    ]
                );

                // Fire cross-module event
                event(new CrossModuleEvent(
                    $user,
                    'sacco',
                    'automated_payment_success',
                    'Automatic Loan Payment Processed',
                    "Your loan payment of UGX " . number_format($result['payment_amount']) . " has been automatically processed from your earnings.",
                    $result
                ));

                Log::info('Automated loan payment successful', [
                    'user_id' => $user->id,
                    'loan_id' => $loan->id,
                    'amount' => $result['payment_amount'],
                    'remaining_balance' => $result['remaining_balance'],
                ]);
            } else {
                // Send failure notification
                $notificationService->sendLoanPaymentNotification(
                    $user,
                    'automated_payment_failed',
                    [
                        'reason' => $result['message'],
                        'loan_id' => $loan->id,
                        'required_amount' => $loan->monthly_payment,
                    ]
                );

                Log::warning('Automated loan payment failed', [
                    'user_id' => $user->id,
                    'loan_id' => $loan->id,
                    'reason' => $result['message'],
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Error processing individual loan payment', [
                'user_id' => $user->id,
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Processing error: ' . $e->getMessage(),
                'payment_amount' => 0,
            ];
        }
    }

    /**
     * Check if loan payment is due
     */
    protected function isPaymentDue($loan): bool
    {
        $lastPayment = $loan->payments()->latest()->first();

        if (!$lastPayment) {
            // No previous payments - check if loan is at least 30 days old
            return $loan->created_at->addDays(30)->isPast();
        }

        // Check if 30 days have passed since last payment
        return $lastPayment->created_at->addDays(30)->isPast();
    }

    /**
     * Send batch summary notification to admins
     */
    protected function sendBatchSummaryNotification(
        array $results,
        CrossModuleNotificationService $notificationService
    ): void {
        try {
            $adminUsers = \App\Models\User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->get();

            $title = 'Automated Loan Payments Processed';
            $message = sprintf(
                'Batch completed: %d processed, %d successful, %d failed. Total: UGX %s',
                $results['processed'],
                $results['successful'],
                $results['failed'],
                number_format($results['total_amount'])
            );

            foreach ($adminUsers as $admin) {
                $notificationService->sendToUser(
                    $admin,
                    'sacco',
                    'batch_payment_summary',
                    $title,
                    $message,
                    $results,
                    route('admin.sacco.loans.index'),
                    'View Loans'
                );
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send batch summary notification: ' . $e->getMessage());
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Automated loan payment job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'dry_run' => $this->dryRun,
            'specific_user_id' => $this->specificUserId,
        ]);

        // Notify admins of job failure
        try {
            $adminUsers = \App\Models\User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->get();

            $notificationService = app(CrossModuleNotificationService::class);

            foreach ($adminUsers as $admin) {
                $notificationService->sendToUser(
                    $admin,
                    'system',
                    'job_failed',
                    'Automated Loan Payment Job Failed',
                    'The automated loan payment processing job has failed permanently: ' . $exception->getMessage(),
                    [
                        'job_class' => self::class,
                        'error' => $exception->getMessage(),
                        'timestamp' => now(),
                    ],
                    route('admin.system.jobs'),
                    'View Jobs'
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to send job failure notification: ' . $e->getMessage());
        }
    }

    /**
     * Calculate retry delay (exponential backoff)
     */
    public function backoff(): array
    {
        return [60, 300, 900]; // 1 min, 5 min, 15 min
    }
}