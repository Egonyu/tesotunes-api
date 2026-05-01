<?php

use App\Jobs\FeedAggregationJob;
use App\Jobs\ScanPaymentIssuesJob;
use App\Jobs\SendEventReminderNotificationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

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

// ── Listen-to-earn daily pool distribution ─────────────────────
// Distributes listen_earn_daily_pool credits to yesterday's listeners at 2 AM EAT
Schedule::command('credits:distribute-listen-earn')
    ->dailyAt('02:00')
    ->name('listen-earn-distribution')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

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
    if (! empty($results['errors'])) {
        $this->warn('Errors: '.count($results['errors']));
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
    $this->info('Found '.count($issues).' new issues');

    $stats = $service->getStatistics();
    $this->table(
        ['Metric', 'Value'],
        collect($stats)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->toArray()
    );
})->purpose('Manually scan for payment issues');

// Manual investigation trigger
Artisan::command('payment:investigate {issueId}', function ($issueId) {
    $issue = \App\Models\PaymentIssue::find($issueId);
    if (! $issue) {
        $this->error("Issue #{$issueId} not found");

        return 1;
    }

    $this->info("Investigating issue #{$issueId}: {$issue->title}");
    $service = app(\App\Services\Payment\PaymentReconciliationService::class);
    $result = $service->investigate($issue);

    if ($result['success']) {
        $this->info('✅ '.$result['message']);
    } else {
        $this->warn('⚠️ '.$result['message']);
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

/*
|--------------------------------------------------------------------------
| Subscription Auto-Renewal & Expiry
|--------------------------------------------------------------------------
|
| Daily checks for expired subscriptions:
| - Auto-renew via ZengaPay for auto_renew=true subscriptions
| - Expire subscriptions where auto_renew=false and expires_at is past
| - Send reminder notifications at 7, 3, and 1 day(s) before expiry
|
*/

// Check expired subscriptions and auto-renew or expire — daily at 6 AM EAT
Schedule::command('subscriptions:check-expired')
    ->dailyAt('06:00')
    ->name('subscription-expiry-check')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Send expiry reminders (7d, 3d, 1d) — daily at 9 AM EAT
Schedule::command('subscriptions:send-expiry-reminders')
    ->dailyAt('09:00')
    ->name('subscription-expiry-reminders')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| API Usage Analytics Aggregation
|--------------------------------------------------------------------------
|
| Aggregate raw API usage logs into hourly rollups for the admin dashboard.
| Prunes raw logs older than 30 days to manage table size.
|
*/

Schedule::command('api-usage:aggregate --prune-days=30')
    ->hourly()
    ->name('api-usage-aggregation')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('observability:maintain')
    ->hourly()
    ->name('observability-maintenance')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Poll Auto-Close
|--------------------------------------------------------------------------
|
| Close active polls whose ends_at has passed. Runs hourly so polls expire
| within ~60 minutes of their scheduled end time.
|
*/

Schedule::command('polls:close-expired')
    ->hourly()
    ->name('polls-close-expired')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Weekly Music Digest Notifications
|--------------------------------------------------------------------------
|
| Send weekly recap emails to active users every Monday at 9 AM EAT.
|
*/

Schedule::command('notifications:weekly-digest')
    ->weeklyOn(1, '09:00')
    ->name('weekly-digest')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Event Reminder Notifications
|--------------------------------------------------------------------------
|
| Send attendee reminders ahead of published events.
|
*/

Schedule::job(new SendEventReminderNotificationsJob(24))
    ->hourly()
    ->name('event-reminders-24h')
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('events:send-reminders {--hours=24 : Hours before start time}', function () {
    $hours = (int) $this->option('hours');
    Bus::dispatchSync(new SendEventReminderNotificationsJob($hours));
    $this->info("Event reminders queued for the {$hours}-hour window.");
})->purpose('Queue attendee reminder notifications for upcoming events');
