<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\FeedAggregationJob;
use App\Jobs\ScanPaymentIssuesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Backup Scheduled Tasks
|--------------------------------------------------------------------------
|
| Database and system backup schedules
|
*/

// Daily database backup at 3 AM (if auto_enabled is true)
if (config('backup.auto_enabled', false)) {
    $schedule = config('backup.schedule', 'daily');
    
    $backupTask = Schedule::command('backup:run --type=database --clean')
        ->name('database-backup')
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground();
    
    switch ($schedule) {
        case 'hourly':
            $backupTask->hourly();
            break;
        case 'twicedaily':
            $backupTask->twiceDaily(1, 13); // 1 AM and 1 PM
            break;
        case 'daily':
            $backupTask->dailyAt('03:00');
            break;
        case 'weekly':
            $backupTask->weekly()->sundays()->at('03:00');
            break;
        case 'monthly':
            $backupTask->monthly()->at('03:00');
            break;
    }

    // Weekly full backup on Sundays at 4 AM
    Schedule::command('backup:run --type=full')
        ->weekly()
        ->sundays()
        ->at('04:00')
        ->name('full-backup')
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground();
    
    // Clean old backups daily at 5 AM
    Schedule::command('backup:clean')
        ->dailyAt('05:00')
        ->name('backup-cleanup')
        ->withoutOverlapping()
        ->onOneServer();
}

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Feed system scheduled jobs
|
*/

// ── Health monitoring ─────────────────────────────────────────
Schedule::command('monitor:health')
    ->everyFiveMinutes()
    ->name('health-monitor')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Feed Aggregation: Process privacy-safe aggregations daily at 2 AM
// // Schedule::job(new FeedAggregationJob)->dailyAt('02:00')
// //     ->name('feed-aggregation')
// //     ->withoutOverlapping()
// //     ->onOneServer();

// Manual trigger command for testing/admin
Artisan::command('feed:aggregate', function () {
    $this->info('Starting feed aggregation...');
    $results = \App\Services\FeedAggregationService::forceProcess();
    $this->info("Processed: {$results['processed']}");
    $this->info("Activities created: {$results['activities_created']}");
    $this->info("Commerce: {$results['commerce']}");
    $this->info("Ojokotau: {$results['ojokotau']}");
    if (!empty($results['errors'])) {
        $this->warn("Errors: " . count($results['errors']));
    }
})->purpose('Manually trigger feed aggregation processing');

/*
|--------------------------------------------------------------------------
| Payment Issue Detection & Auto-Resolution
|--------------------------------------------------------------------------
|
| Scans for stuck/failed payments and attempts automatic resolution.
| Notifies admins and users about payment issues.
|
*/

// Scan for payment issues every 5 minutes
// // Schedule::job(new ScanPaymentIssuesJob)->everyFiveMinutes()
//     ->name('payment-issues-scan')
//     ->withoutOverlapping()
//     ->onOneServer();

// Reconcile stuck payments every 10 minutes
// This checks payments in 'processing' status and reconciles with ZengaPay
Schedule::command('payments:reconcile-stuck --minutes=15')
    ->everyTenMinutes()
    ->name('payment-reconciliation')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Manual trigger command for payment issues
Artisan::command('payment:scan-issues', function () {
    $this->info('Scanning for payment issues...');
    $service = app(\App\Services\Payment\PaymentReconciliationService::class);
    $issues = $service->scanForIssues();
    $this->info("Found " . count($issues) . " new issues");
    
    $stats = $service->getStatistics();
    $this->table(
        ['Metric', 'Value'],
        collect($stats)->map(fn($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->toArray()
    );
})->purpose('Manually scan for payment issues');

// Manual investigation trigger
Artisan::command('payment:investigate {issueId}', function ($issueId) {
    $issue = \App\Models\PaymentIssue::find($issueId);
    if (!$issue) {
        $this->error("Issue #{$issueId} not found");
        return 1;
    }
    
    $this->info("Investigating issue #{$issueId}: {$issue->title}");
    $service = app(\App\Services\Payment\PaymentReconciliationService::class);
    $result = $service->investigate($issue);
    
    if ($result['success']) {
        $this->info("✅ " . $result['message']);
    } else {
        $this->warn("⚠️ " . $result['message']);
    }
    
    return $result['success'] ? 0 : 1;
})->purpose('Manually investigate a specific payment issue');

// Retroactive scan for historical failed/stuck payments
Artisan::command('payment:scan-historical {--days=7 : Days to look back}', function ($days) {
    $this->info("Scanning for historical payment issues from the last {$days} days...");
    
    $service = app(\App\Services\Payment\PaymentReconciliationService::class);
    $created = 0;
    
    // Find stuck processing payments
    $stuckPayments = \App\Models\Payment::where('status', 'processing')
        ->where('created_at', '>', now()->subDays($days))
        ->whereDoesntHave('issues')
        ->get();
    
    $this->info("Found {$stuckPayments->count()} stuck processing payments");
    
    foreach ($stuckPayments as $payment) {
        $issue = $service->detectIssue($payment, \App\Models\PaymentIssue::TYPE_STUCK_PROCESSING, [
            'money_deducted' => true,
            'service_delivered' => false,
            'description' => "Historical: Payment stuck in processing since {$payment->created_at}",
        ]);
        $created++;
        $this->line("Created issue #{$issue->id} for payment #{$payment->id}");
    }
    
    // Find failed payments with high amounts that might need review
    $failedPayments = \App\Models\Payment::where('status', 'failed')
        ->where('created_at', '>', now()->subDays($days))
        ->where('amount', '>=', 5000)
        ->whereDoesntHave('issues')
        ->get();
    
    $this->info("Found {$failedPayments->count()} failed high-value payments");
    
    foreach ($failedPayments as $payment) {
        $issue = $service->detectIssue($payment, \App\Models\PaymentIssue::TYPE_PROVIDER_ERROR, [
            'money_deducted' => false,
            'service_delivered' => false,
            'description' => "Historical review: Failed payment of UGX {$payment->amount}. Reason: {$payment->failure_reason}",
        ]);
        $created++;
        $this->line("Created issue #{$issue->id} for payment #{$payment->id}");
    }
    
    $this->info("✅ Created {$created} payment issues for review");
})->purpose('Create payment issues for historical failed/stuck payments');
