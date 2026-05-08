<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SACCO Domain Routes
|--------------------------------------------------------------------------
|
| All SACCO (savings cooperative) routes live here.
| User-facing routes require auth + sacco membership.
| Admin routes require auth + admin role.
|
*/

// ── Admin SACCO Routes ────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])
    ->prefix('admin')
    ->name('api.admin.')
    ->group(function () {

        // Member management & loan oversight
        Route::get('/sacco/stats', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'stats'])->name('sacco.stats');
        Route::get('/sacco/members', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'members'])->name('sacco.members');
        Route::get('/sacco/members/{id}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'showMember'])->name('sacco.members.show');
        Route::get('/sacco/members/{id}/transactions', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'memberTransactions'])->name('sacco.members.transactions');
        Route::get('/sacco/members/{id}/loans', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'memberLoans'])->name('sacco.members.loans');
        Route::get('/sacco/loans', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'loans'])->name('sacco.loans');
        Route::get('/sacco/loans/{id}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'showLoan'])->name('sacco.loans.show');
        Route::post('/sacco/loans/{id}/approve', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'approveLoan'])->name('sacco.loans.approve');
        Route::post('/sacco/loans/{id}/reject', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'rejectLoan'])->name('sacco.loans.reject');
        Route::post('/sacco/loans/{id}/disburse', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'disburseLoan'])->name('sacco.loans.disburse');
        Route::get('/sacco/loans/{id}/repayments', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'loanRepayments'])->name('sacco.loans.repayments');
        Route::get('/sacco/transactions', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoApiController::class, 'savingsTransactions'])->name('sacco.transactions');

        // Board meetings & governance
        Route::get('/sacco/board-members', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoBoardMeetingsController::class, 'boardMembers'])->name('sacco.board-members');
        Route::get('/sacco/board-meetings', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoBoardMeetingsController::class, 'index'])->name('sacco.board-meetings.index');
        Route::post('/sacco/board-meetings', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoBoardMeetingsController::class, 'store'])->name('sacco.board-meetings.store');
        Route::get('/sacco/board-meetings/{id}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoBoardMeetingsController::class, 'show'])->name('sacco.board-meetings.show');
        Route::put('/sacco/board-meetings/{id}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoBoardMeetingsController::class, 'update'])->name('sacco.board-meetings.update');
        Route::delete('/sacco/board-meetings/{id}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoBoardMeetingsController::class, 'destroy'])->name('sacco.board-meetings.destroy');
        Route::get('/sacco/meetings', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'meetings'])->name('sacco.meetings.index');
        Route::post('/sacco/meetings', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'storeMeeting'])->name('sacco.meetings.store');
        Route::get('/sacco/meetings/attendance-summary', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'attendanceSummary'])->name('sacco.meetings.attendance-summary');
        Route::get('/sacco/meetings/{meeting}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'showMeeting'])->name('sacco.meetings.show');
        Route::put('/sacco/meetings/{meeting}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'updateMeeting'])->name('sacco.meetings.update');
        Route::delete('/sacco/meetings/{meeting}', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'destroyMeeting'])->name('sacco.meetings.destroy');
        Route::get('/sacco/meetings/{meeting}/attendance', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'attendance'])->name('sacco.meetings.attendance');
        Route::post('/sacco/meetings/{meeting}/attendance', [\App\Modules\Sacco\Http\Controllers\Admin\SaccoGovernanceController::class, 'markAttendance'])->name('sacco.meetings.attendance.mark');
    });

// ── User-Facing SACCO Routes ──────────────────────────────────────────────────
Route::prefix('sacco')
    ->middleware(['auth:sanctum'])
    ->name('api.sacco.')
    ->group(function () {
        // Membership entrypoints (no SACCO membership required yet)
        Route::get('membership', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'myMembership'])->name('membership');
        Route::post('join', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'join'])->name('join');

        Route::middleware('sacco.member.api')->group(function () {
            Route::get('/', \App\Modules\Sacco\Http\Controllers\SaccoIndexController::class)->name('index');
            Route::get('dashboard', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'dashboard'])->name('dashboard');
            Route::get('profile', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'profile'])->name('profile');
            Route::get('transactions', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'memberTransactions'])->name('transactions.index');

            // Membership
            Route::get('members', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'index'])->name('members.index');
            Route::post('members', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'store'])->name('members.store');
            Route::get('members/{member}', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'show'])->name('members.show');
            Route::put('members/{member}', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'update'])->name('members.update');
            Route::patch('members/{member}/status', [\App\Modules\Sacco\Http\Controllers\SaccoMembershipController::class, 'updateStatus'])->name('members.status');

            // Savings
            Route::get('savings', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'summary'])->name('savings.summary');
            Route::prefix('savings')->name('savings.')->group(function () {
                Route::post('accounts', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'openAccount'])->name('accounts.open');
                Route::post('deposit', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'deposit'])->name('deposit');
                Route::post('withdraw', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'withdraw'])->name('withdraw');
                Route::get('accounts/{account}', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'show'])->name('accounts.show');
                Route::get('transactions/{account}', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'transactions'])->name('transactions');
                Route::get('balance/{account}', [\App\Modules\Sacco\Http\Controllers\SaccoSavingsController::class, 'balance'])->name('balance');
            });

            // Loans
            Route::get('loan-products', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'products'])->name('loan-products.index');
            Route::prefix('loans')->name('loans.')->group(function () {
                Route::get('', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'myLoans'])->name('index');
                Route::get('guarantors', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'guarantors'])->name('guarantors');
                Route::post('apply', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'apply'])->name('apply');
                Route::get('eligibility', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'eligibility'])->name('eligibility');
                Route::post('calculate-schedule', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'calculateSchedule'])->name('calculate-schedule');
                Route::post('{loan}/approve', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'approve'])->name('approve');
                Route::post('{loan}/disburse', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'disburse'])->name('disburse');
                Route::post('{loan}/repay', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'repay'])->name('repay');
                Route::post('{loan}/pay', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'repay'])->name('pay');
                Route::get('{loan}', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'show'])->name('show');
                Route::get('member/{member}', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'memberLoans'])->name('member');
                Route::get('{loan}/schedule', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'schedule'])->name('schedule');
                Route::get('{loan}/balance', [\App\Modules\Sacco\Http\Controllers\SaccoLoanController::class, 'balance'])->name('balance');
            });

            // Shares
            Route::get('shares', [\App\Modules\Sacco\Http\Controllers\SaccoSharesController::class, 'myShares'])->name('shares.self');
            Route::prefix('shares')->name('shares.')->group(function () {
                Route::post('purchase', [\App\Modules\Sacco\Http\Controllers\SaccoSharesController::class, 'purchase'])->name('purchase');
                Route::post('buy', [\App\Modules\Sacco\Http\Controllers\SaccoSharesController::class, 'purchase'])->name('buy');
                Route::post('transfer', [\App\Modules\Sacco\Http\Controllers\SaccoSharesController::class, 'transfer'])->name('transfer');
                Route::get('member/{member}', [\App\Modules\Sacco\Http\Controllers\SaccoSharesController::class, 'memberShares'])->name('member');
                Route::get('value', [\App\Modules\Sacco\Http\Controllers\SaccoSharesController::class, 'currentValue'])->name('value');
            });

            // Meetings
            Route::get('meetings', [\App\Modules\Sacco\Http\Controllers\SaccoMeetingsController::class, 'index'])->name('meetings.index');
            Route::get('meetings/{meeting}', [\App\Modules\Sacco\Http\Controllers\SaccoMeetingsController::class, 'show'])->name('meetings.show');
            Route::post('meetings/{meeting}/rsvp', [\App\Modules\Sacco\Http\Controllers\SaccoMeetingsController::class, 'rsvp'])->name('meetings.rsvp');
            Route::get('notifications', [\App\Modules\Sacco\Http\Controllers\SaccoNotificationsController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [\App\Modules\Sacco\Http\Controllers\SaccoNotificationsController::class, 'markAllRead'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [\App\Modules\Sacco\Http\Controllers\SaccoNotificationsController::class, 'markRead'])->name('notifications.read');

            // Goals
            Route::prefix('goals')->name('goals.')->group(function () {
                Route::get('', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'index'])->name('index');
                Route::post('', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'store'])->name('store');
                Route::get('{goal}', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'show'])->name('show');
                Route::put('{goal}', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'update'])->name('update');
                Route::delete('{goal}', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'destroy'])->name('destroy');
                Route::post('{goal}/deposit', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'deposit'])->name('deposit');
                Route::post('{goal}/convert-credits', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'convertCredits'])->name('convert-credits');
                Route::post('{goal}/auto-save', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'autoSave'])->name('auto-save');
                Route::get('{goal}/transactions', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'transactions'])->name('transactions');
                Route::get('{goal}/funding-options', [\App\Modules\Sacco\Http\Controllers\SaccoGoalsController::class, 'fundingOptions'])->name('funding-options');
            });

            // Reports
            Route::prefix('reports')->name('reports.')->group(function () {
                Route::get('membership', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'membership'])->name('membership');
                Route::get('loans', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'loans'])->name('loans');
                Route::get('savings', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'savings'])->name('savings');
                Route::get('shares', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'shares'])->name('shares');
                Route::get('financial', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'financial'])->name('financial');
                Route::get('member/{member}', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'memberStatement'])->name('member');
                Route::get('overdue', [\App\Modules\Sacco\Http\Controllers\SaccoReportsController::class, 'overdue'])->name('overdue');
            });

            // Analytics
            Route::prefix('analytics')->name('analytics.')->group(function () {
                Route::get('dashboard', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'dashboard'])->name('dashboard');
                Route::get('trends/membership', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'membershipTrends'])->name('trends.membership');
                Route::get('performance/loans', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'loanPerformance'])->name('performance.loans');
                Route::get('savings', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'savings'])->name('savings');
                Route::get('repayments', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'repayments'])->name('repayments');
                Route::get('portfolio', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'portfolio'])->name('portfolio');
                Route::get('activity', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'activity'])->name('activity');
                Route::get('top-performers', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'topPerformers'])->name('top-performers');
                Route::get('risk', [\App\Modules\Sacco\Http\Controllers\SaccoAnalyticsController::class, 'risk'])->name('risk');
            });
        });
    });
