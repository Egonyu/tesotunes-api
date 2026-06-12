<?php

use Illuminate\Support\Facades\Route;

// Admin Payment Analytics
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/payment-analytics', [\App\Http\Controllers\Api\PaymentController::class, 'analytics'])->name('payment-analytics');
    Route::get('/payments/observability', [\App\Http\Controllers\Api\Admin\PaymentObservabilityController::class, 'dashboard'])->name('payments.observability');
    Route::get('/payments/entry-points', [\App\Http\Controllers\Api\Admin\PaymentObservabilityController::class, 'entryPoints'])->name('payments.entry-points');
    Route::get('/payment-issues', [\App\Http\Controllers\Api\Admin\PaymentObservabilityController::class, 'issues'])->name('payment-issues.index');
    Route::get('/payments', [\App\Http\Controllers\Api\Admin\PaymentObservabilityController::class, 'payments'])->name('payments.index');
    Route::get('/payments/{payment}', [\App\Http\Controllers\Api\Admin\PaymentObservabilityController::class, 'show'])->name('payments.show');
});

// Admin Dashboard & Settings API — SECURED (dashboard stats are sensitive)
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/dashboard/stats', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'stats'])->name('dashboard.stats');
    Route::get('/dashboard/recent-activity', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'recentActivity'])->name('dashboard.recent-activity');

    Route::get('/analytics', [\App\Http\Controllers\Api\Admin\AdminAnalyticsController::class, 'overview'])->name('analytics.overview');

    Route::get('/analytics/api-usage', [\App\Http\Controllers\Api\Admin\ApiAnalyticsController::class, 'dashboard'])->name('analytics.api-usage');
    Route::get('/analytics/top-users', [\App\Http\Controllers\Api\Admin\ApiAnalyticsController::class, 'topUsers'])->name('analytics.top-users');
    Route::get('/analytics/api-usage/top-users', [\App\Http\Controllers\Api\Admin\ApiAnalyticsController::class, 'topUsers'])->name('analytics.api-usage.top-users');
});

// Super-admin only settings (environment variables)
Route::middleware(['auth:sanctum', 'role:super_admin', 'admin.exceptions'])->prefix('admin/settings')->name('api.admin.settings.')->group(function () {
    Route::get('/environment', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'environmentIndex'])->name('environment.index');
    Route::put('/environment', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'updateEnvironment'])->name('environment.update');
});

// Moderator-safe read and moderation workflows
Route::middleware(['auth:sanctum', 'role:admin,super_admin,moderator', 'admin.exceptions'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/users', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'index'])->name('users.index');
    Route::get('/users/statistics', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'statistics'])->name('users.statistics');
    Route::get('/users/{id}/access-history', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'accessHistory'])->name('users.access-history');
    Route::get('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'show'])->name('users.show');

    Route::get('/reports/stats', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'stats'])->name('reports.stats');
    Route::get('/reports', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'index'])->name('reports.index');
    Route::post('/reports/{report}/status', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'updateStatus'])->name('reports.status');

    Route::get('/songs/statistics', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'statistics'])->name('songs.statistics');
    Route::post('/songs/bulk-approve', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'bulkApprove'])->name('songs.bulk-approve');
    Route::post('/songs/bulk-reject', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'bulkReject'])->name('songs.bulk-reject');
    Route::get('/songs', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'index'])->name('songs.index');
    Route::get('/songs/{id}', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'show'])->name('songs.show');
    Route::post('/songs', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'store'])->name('songs.store');
    Route::put('/songs/{id}', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'update'])->name('songs.update');
    Route::post('/songs/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'toggleStatus'])->name('songs.toggle-status');
    Route::get('/songs/{id}/play-history', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'playHistory'])->name('songs.play-history');
});

// Full admin operations
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/', \App\Http\Controllers\Api\Admin\AdminIndexController::class)->name('index');

    // Settings API
    Route::get('/settings', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'update'])->name('settings.update');

    // Settings Registry API (per-field, audited)
    Route::get('/settings/schema', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'schema'])->name('settings.schema');
    Route::get('/settings/values', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'values'])->name('settings.values');
    Route::patch('/settings', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'patchBatch'])->name('settings.patch-batch');
    Route::patch('/settings/{key}', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'patchOne'])
        ->where('key', '[A-Za-z0-9_.-]+')
        ->name('settings.patch-one');
    Route::get('/settings/{key}/history', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'history'])
        ->where('key', '[A-Za-z0-9_.-]+')
        ->name('settings.history');
    Route::post('/settings/{key}/revert/{auditId}', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'revert'])
        ->where(['key' => '[A-Za-z0-9_.-]+', 'auditId' => '[0-9]+'])
        ->name('settings.revert');

    Route::get('/audit-logs', [\App\Http\Controllers\Api\Admin\AdminAuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/feature-flags', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'index'])->name('feature-flags.index');
    Route::post('/feature-flags', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'store'])->name('feature-flags.store');
    Route::put('/feature-flags/{id}', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'update'])->name('feature-flags.update');
    Route::delete('/feature-flags/{id}', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'destroy'])->name('feature-flags.destroy');

    // Users API (mutating)
    Route::post('/users', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'store'])->name('users.store');
    Route::put('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/ban', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'ban'])->name('users.ban');
    Route::post('/users/{id}/activate', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'activate'])->name('users.activate');
    Route::delete('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'destroy'])->name('users.destroy');

    // Events API
    Route::get('/events/stats', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'stats'])->name('events.stats');
    Route::get('/events', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'index'])->name('events.index');
    Route::get('/events/{id}', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'show'])->name('events.show');
    Route::post('/events', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'store'])->name('events.store');
    Route::put('/events/{id}', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'update'])->name('events.update');
    Route::delete('/events/{id}', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'destroy'])->name('events.destroy');
    Route::post('/events/{id}/publish', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'publish'])->name('events.publish');
    Route::post('/events/{id}/toggle-featured', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'toggleFeatured'])->name('events.toggle-featured');
    Route::get('/events/{id}/analytics', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'analytics'])->name('events.analytics');
    Route::get('/events/{id}/analytics/export', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'exportAnalytics'])->name('events.analytics.export');
    Route::get('/events/{id}/attendees', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'attendees'])->name('events.attendees');
    Route::get('/events/{id}/registrations', [\App\Modules\Events\Http\Controllers\Admin\EventsApiController::class, 'registrations'])->name('events.registrations');

    // Ad management
    Route::get('/ads/analytics', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'analytics'])->name('ads.analytics');
    Route::get('/ads', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'index'])->name('ads.index');
    Route::post('/ads', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'store'])->name('ads.store');
    Route::get('/ads/{ad}', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'show'])->name('ads.show');
    Route::put('/ads/{ad}', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'update'])->name('ads.update');
    Route::delete('/ads/{ad}', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'destroy'])->name('ads.destroy');
    Route::post('/ads/{ad}/activate', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'activate'])->name('ads.activate');
    Route::post('/ads/{ad}/pause', [\App\Http\Controllers\Api\Admin\AdminAdsController::class, 'pause'])->name('ads.pause');

    // Ad placement zone management
    Route::get('/ad-placements/analytics', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'analytics'])->name('ad-placements.analytics');
    Route::get('/ad-placements', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'index'])->name('ad-placements.index');
    Route::get('/ad-placements/{key}', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'show'])->name('ad-placements.show');
    Route::put('/ad-placements/{key}', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'update'])->name('ad-placements.update');
    Route::post('/ad-placements/{key}/assign', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'assign'])->name('ad-placements.assign');
    Route::put('/ad-placements/{key}/assign/{assignment}', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'updateAssignment'])->name('ad-placements.assign.update');
    Route::delete('/ad-placements/{key}/assign/{assignment}', [\App\Http\Controllers\Api\Admin\AdminAdPlacementController::class, 'removeAssignment'])->name('ad-placements.assign.destroy');

    // Promotions moderation
    Route::get('/promotions', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'index'])->name('promotions.index');
    Route::get('/promotions/analytics', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'analytics'])->name('promotions.analytics');
    Route::get('/promotions/disputes', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'disputes'])->name('promotions.disputes');
    Route::post('/promotions/{promotion}/approve', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'approve'])->name('promotions.approve');
    Route::post('/promotions/{promotion}/reject', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'reject'])->name('promotions.reject');
    Route::post('/promotions/disputes/{disputeId}/resolve', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'resolveDispute'])->name('promotions.disputes.resolve');

    // Campaigns API
    Route::get('/campaigns/stats', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'stats'])->name('campaigns.stats');
    Route::get('/campaigns', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/{id}', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'show'])->name('campaigns.show');
    Route::post('/campaigns', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'store'])->name('campaigns.store');
    Route::put('/campaigns/{id}', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'update'])->name('campaigns.update');
    Route::delete('/campaigns/{id}', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'destroy'])->name('campaigns.destroy');
    Route::post('/campaigns/{id}/approve', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'approve'])->name('campaigns.approve');
    Route::post('/campaigns/{id}/reject', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'reject'])->name('campaigns.reject');
    Route::get('/campaigns/{id}/pledges', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'pledges'])->name('campaigns.pledges');
    Route::get('/campaigns/{id}/updates', [\App\Http\Controllers\Api\Admin\CampaignsApiController::class, 'updates'])->name('campaigns.updates');

    // Forums API
    Route::get('/forums/stats', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'stats'])->name('forums.stats');
    Route::get('/forums/categories', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'categories'])->name('forums.categories.index');
    Route::post('/forums/categories', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'storeCategory'])->name('forums.categories.store');
    Route::get('/forums/categories/{id}', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'showCategory'])->name('forums.categories.show');
    Route::put('/forums/categories/{id}', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'updateCategory'])->name('forums.categories.update');
    Route::delete('/forums/categories/{id}', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'destroyCategory'])->name('forums.categories.destroy');
    Route::get('/forums', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'index'])->name('forums.index');
    Route::post('/forums', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'store'])->name('forums.store');
    Route::get('/forums/{id}', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'show'])->name('forums.show')->where('id', '[0-9]+');
    Route::put('/forums/{id}', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'update'])->name('forums.update')->where('id', '[0-9]+');
    Route::delete('/forums/{id}', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'destroy'])->name('forums.destroy')->where('id', '[0-9]+');
    Route::post('/forums/{id}/pin', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'togglePin'])->name('forums.pin')->where('id', '[0-9]+');
    Route::post('/forums/{id}/lock', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'toggleLock'])->name('forums.lock')->where('id', '[0-9]+');
    Route::get('/forums/{id}/replies', [\App\Http\Controllers\Api\Admin\ForumsApiController::class, 'replies'])->name('forums.replies')->where('id', '[0-9]+');

    // Polls API
    Route::get('/polls/stats', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'stats'])->name('polls.stats');
    Route::get('/polls', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'index'])->name('polls.index');
    Route::post('/polls', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'store'])->name('polls.store');
    Route::get('/polls/{id}', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'show'])->name('polls.show');
    Route::put('/polls/{id}', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'update'])->name('polls.update');
    Route::delete('/polls/{id}', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'destroy'])->name('polls.destroy');
    Route::post('/polls/{id}/close', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'close'])->name('polls.close');
    Route::post('/polls/{id}/reopen', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'reopen'])->name('polls.reopen');
    Route::get('/polls/{id}/analytics', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'analytics'])->name('polls.analytics');
    Route::get('/polls/{id}/export', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'export'])->name('polls.export');

    // Song destructive/curation routes
    Route::delete('/songs/{id}', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'destroy'])->name('songs.destroy');
    Route::post('/songs/{id}/toggle-featured', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'toggleFeatured'])->name('songs.toggle-featured');

    // Albums API
    Route::get('/albums/statistics', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'statistics'])->name('albums.statistics');
    Route::get('/albums', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'index'])->name('albums.index');
    Route::post('/albums', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'store'])->name('albums.store');
    Route::get('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'show'])->name('albums.show');
    Route::put('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'update'])->name('albums.update');
    Route::post('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'update'])->name('albums.update.post');
    Route::delete('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'destroy'])->name('albums.destroy');
    Route::post('/albums/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'toggleStatus'])->name('albums.toggle-status');

    // Awards API
    Route::get('/awards/stats', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'stats'])->name('awards.stats');
    Route::get('/awards', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'index'])->name('awards.index');
    Route::get('/awards/seasons', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'seasons'])->name('awards.seasons');
    Route::get('/awards/seasons/{id}', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'showSeason'])->name('awards.seasons.show');
    Route::post('/awards/seasons', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'storeSeason'])->name('awards.seasons.store');
    Route::put('/awards/seasons/{id}', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'updateSeason'])->name('awards.seasons.update');
    Route::delete('/awards/seasons/{id}', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'destroySeason'])->name('awards.seasons.destroy');
    Route::get('/awards/categories', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'categories'])->name('awards.categories.index');
    Route::get('/awards/categories/{id}', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'showCategory'])->name('awards.categories.show');
    Route::post('/awards/categories', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'storeCategory'])->name('awards.categories.store');
    Route::put('/awards/categories/{id}', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'updateCategory'])->name('awards.categories.update');
    Route::delete('/awards/categories/{id}', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'destroyCategory'])->name('awards.categories.destroy');
    Route::get('/awards/nominations', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'nominations'])->name('awards.nominations.index');
    Route::post('/awards/nominations', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'storeNomination'])->name('awards.nominations.store');
    Route::post('/awards/nominations/{id}/approve', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'approveNomination'])->name('awards.nominations.approve');
    Route::post('/awards/nominations/{id}/reject', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'rejectNomination'])->name('awards.nominations.reject');
    Route::post('/awards/nominations/{id}/set-winner', [\App\Http\Controllers\Api\Admin\AdminAwardsApiController::class, 'setWinner'])->name('awards.nominations.set-winner');

    // Roles & Permissions API
    Route::get('/roles', [\App\Http\Controllers\Api\Admin\RoleController::class, 'index'])->name('roles.index');
    Route::get('/role-templates', [\App\Http\Controllers\Api\Admin\RoleController::class, 'templates'])->name('roles.templates');
    Route::post('/role-templates', [\App\Http\Controllers\Api\Admin\RoleController::class, 'storeTemplate'])->name('roles.templates.store');
    Route::delete('/role-templates/{roleTemplate}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'destroyTemplate'])->name('roles.templates.destroy');
    Route::get('/permissions', [\App\Http\Controllers\Api\Admin\RoleController::class, 'permissions'])->name('permissions.index');
    Route::get('/roles/permissions', [\App\Http\Controllers\Api\Admin\RoleController::class, 'permissions'])->name('roles.permissions');
    Route::get('/roles/{role}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'show'])->name('roles.show');
    Route::post('/roles', [\App\Http\Controllers\Api\Admin\RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles/{role}/permissions', [\App\Http\Controllers\Api\Admin\RoleController::class, 'updatePermissions'])->name('roles.update-permissions');
    Route::put('/roles/{role}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'destroy'])->name('roles.destroy');
    Route::post('/roles/assign', [\App\Http\Controllers\Api\Admin\RoleController::class, 'assignToUser'])->name('roles.assign');
    Route::post('/roles/assign-bulk', [\App\Http\Controllers\Api\Admin\RoleController::class, 'assignToUsers'])->name('roles.assign-bulk');
    Route::post('/roles/remove', [\App\Http\Controllers\Api\Admin\RoleController::class, 'removeFromUser'])->name('roles.remove');
    Route::post('/roles/remove-bulk', [\App\Http\Controllers\Api\Admin\RoleController::class, 'removeFromUsers'])->name('roles.remove-bulk');

    // Subscription Management API
    Route::get('/subscriptions/stats', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'stats'])->name('subscriptions.stats');
    Route::get('/subscriptions', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'index'])->name('subscriptions.index');
    Route::get('/subscriptions/export', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'exportIndex'])->name('subscriptions.export');
    Route::get('/subscriptions/rates', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'rates'])->name('subscriptions.rates');
    Route::get('/subscriptions/rates/export', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'exportRates'])->name('subscriptions.rates.export');
    Route::put('/subscriptions/rates', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'updateRates'])->name('subscriptions.rates.update');
    Route::get('/subscriptions/{id}', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'show'])->name('subscriptions.show');
    Route::post('/subscriptions/grant', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'grant'])->name('subscriptions.grant');
    Route::post('/subscriptions/{id}/revoke', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'revoke'])->name('subscriptions.revoke');
    Route::get('/subscription-plans', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'plansList'])->name('subscription-plans.index');
    Route::get('/subscription-plans/export', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'exportPlans'])->name('subscription-plans.export');
    Route::post('/subscription-plans', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'storePlan'])->name('subscription-plans.store');
    Route::put('/subscription-plans/{id}', [\App\Http\Controllers\Api\Admin\AdminSubscriptionsController::class, 'updatePlan'])->name('subscription-plans.update');

    // Podcasts API
    Route::get('/podcasts/stats', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'stats'])->name('podcasts.stats');
    Route::get('/podcasts/categories', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'categories'])->name('podcasts.categories');
    Route::get('/podcasts', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'index'])->name('podcasts.index');
    Route::get('/podcasts/{id}', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'show'])->name('podcasts.show');
    Route::post('/podcasts', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'store'])->name('podcasts.store');
    Route::put('/podcasts/{id}', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'update'])->name('podcasts.update');
    Route::delete('/podcasts/{id}', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'destroy'])->name('podcasts.destroy');
    Route::post('/podcasts/{id}/approve', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'approve'])->name('podcasts.approve');
    Route::post('/podcasts/{id}/suspend', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'suspend'])->name('podcasts.suspend');
    Route::get('/podcasts/{id}/episodes', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'episodes'])->name('podcasts.episodes');

    // Genres API
    Route::get('/genres', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'index'])->name('genres.index');
    Route::post('/genres', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'store'])->name('genres.store');
    Route::get('/genres/{id}', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'show'])->name('genres.show');
    Route::put('/genres/{id}', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'update'])->name('genres.update');
    Route::delete('/genres/{id}', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'destroy'])->name('genres.destroy');
    Route::post('/genres/{id}/toggle-active', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'toggleActive'])->name('genres.toggle-active');

    Route::get('/reports/export', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'exportReports'])->name('reports.export');
    Route::get('/reports/streaming-payouts', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'streamingPayouts'])->name('reports.streaming-payouts');
    Route::get('/reports/streaming-payouts/export', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'exportStreamingPayouts'])->name('reports.streaming-payouts.export');

    // System health & operations
    Route::get('/system/health', [\App\Http\Controllers\Api\Admin\AdminSystemController::class, 'health'])->name('system.health');
    Route::get('/system/tests', [\App\Http\Controllers\Api\Admin\AdminSystemController::class, 'tests'])->name('system.tests');
    Route::post('/system/actions', [\App\Http\Controllers\Api\Admin\AdminSystemController::class, 'action'])->name('system.actions');

    // Observability
    Route::prefix('observability')->name('observability.')->group(function () {
        // Security console v2 — push-based read API (no sync-on-read)
        Route::prefix('console')->name('console.')->group(function () {
            Route::get('/posture', [\App\Http\Controllers\Api\Admin\SecurityConsoleController::class, 'posture'])->name('posture');
            Route::get('/feed', [\App\Http\Controllers\Api\Admin\SecurityConsoleController::class, 'feed'])->name('feed');
            Route::get('/incidents', [\App\Http\Controllers\Api\Admin\SecurityConsoleController::class, 'incidents'])->name('incidents');
            Route::get('/domain/{domain}', [\App\Http\Controllers\Api\Admin\SecurityConsoleController::class, 'domain'])->name('domain');
        });

        Route::get('/overview', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'overview'])->name('overview');
        Route::get('/events', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'events'])->name('events.index');
        Route::get('/events/{event}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'showEvent'])->name('events.show');
        Route::get('/entry-points', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'entryPoints'])->name('entry-points');
        Route::get('/attackers', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'attackers'])->name('attackers.index');
        Route::get('/attackers/{attacker}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'showAttacker'])->name('attackers.show');
        Route::get('/bots', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'bots'])->name('bots');
        Route::get('/auth-sessions', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'authSessions'])->name('auth-sessions');
        Route::get('/auth-sessions/{session}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'showAuthSession'])->name('auth-sessions.show');
        Route::get('/payments-risk', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'paymentsRisk'])->name('payments-risk');
        Route::get('/payments-risk/{reference}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'showPaymentReference'])->name('payments-risk.show');
        Route::get('/integrations', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'integrations'])->name('integrations');
        Route::get('/system-host', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'systemHost'])->name('system-host');
        Route::get('/database', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'database'])->name('database');
        Route::get('/audit-trail', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'auditTrail'])->name('audit-trail');
        Route::get('/changes', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'changes'])->name('changes');
        Route::get('/stakeholder-risk', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'stakeholderRisk'])->name('stakeholder-risk');
        Route::get('/stakeholder-risk/{actorType}/{actorId}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'showStakeholder'])->name('stakeholder-risk.show');
        Route::get('/incidents', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'incidents'])->name('incidents.index');
        Route::get('/incidents/suggestions', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'incidentSuggestions'])->name('incidents.suggestions');
        Route::get('/incidents/{incident}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'showIncident'])->name('incidents.show');
        Route::patch('/incidents/{incident}/assign', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'assignIncident'])->name('incidents.assign');
        Route::patch('/incidents/{incident}/release', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'releaseIncident'])->name('incidents.release');
        Route::post('/incidents', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'storeIncident'])->name('incidents.store');
        Route::patch('/incidents/{incident}', [\App\Http\Controllers\Api\Admin\ObservabilityController::class, 'updateIncident'])->name('incidents.update');
    });
});

// Payment Webhooks (Public - no auth required, rate limited)
Route::middleware('webhook.rate_limit')->group(function () {
    Route::post('/webhooks/payment/{provider}', [\App\Http\Controllers\Api\PaymentController::class, 'webhook'])->name('api.webhooks.payment');
    Route::post('/payments/webhook', [\App\Http\Controllers\Api\PaymentController::class, 'webhook'])->name('api.payments.webhook');
    Route::post('/webhooks/mobile-money', [\App\Http\Controllers\Api\MobileMoneyWebhookController::class, 'handle'])->name('webhooks.mobile-money');
});

// Observability event ingestion (platform collectors)
Route::middleware(['observability.collector'])->prefix('observability/collector')->name('api.observability.collector.')->group(function () {
    Route::post('/events', [\App\Http\Controllers\Api\ObservabilityCollectorController::class, 'ingest'])->name('events.ingest');
});
