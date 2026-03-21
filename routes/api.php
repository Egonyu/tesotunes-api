<?php

use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
| These routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Health check endpoints
Route::get('/health', [HealthCheckController::class, 'index']);
Route::get('/health/detailed', [HealthCheckController::class, 'detailed']);
Route::get('/health/system', [HealthCheckController::class, 'system']);

// Authentication API Routes
require __DIR__.'/api/auth.php';

// Music API Routes
require __DIR__.'/api/music.php';

// Engagement API Routes
require __DIR__.'/api/engagement.php';

// Payment API Routes (Day 4)
require __DIR__.'/api/payment.php';

// Webhook API Routes (ZengaPay, etc.)
require __DIR__.'/api/webhooks.php';

// Social API Routes (follows, shares, comments)
require __DIR__.'/api/social.php';

// Posts API Routes (Edula social posts)
require __DIR__.'/api/posts.php';

// Announcements (public)
Route::get('/announcements', [\App\Http\Controllers\Api\FeedController::class, 'announcements'])->name('api.announcements');

// Loyalty API Routes (fan clubs, memberships, rewards, points)
require __DIR__.'/api/loyalty.php';

// Ojokotau / Crowdfunding Campaign API Routes (public browsing + authenticated actions)
require __DIR__.'/api/campaigns.php';

// Homepage featured content
Route::get('/featured', [\App\Http\Controllers\Api\FeaturedContentController::class, 'index'])->name('api.featured');

// Public Events API Routes (no auth required)
Route::prefix('events')->name('api.events.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PublicEventsController::class, 'index'])->name('index');
    Route::get('/featured', [\App\Http\Controllers\Api\PublicEventsController::class, 'featured'])->name('featured');
    Route::get('/upcoming', [\App\Http\Controllers\Api\PublicEventsController::class, 'upcoming'])->name('upcoming');
    Route::get('/categories', [\App\Http\Controllers\Api\PublicEventsController::class, 'categories'])->name('categories');
    Route::get('/{id}', [\App\Http\Controllers\Api\PublicEventsController::class, 'show'])->name('show');
});

Route::middleware('auth:sanctum')->post('/events/{id}/waitlist', [\App\Http\Controllers\Api\PublicEventsController::class, 'joinWaitlist'])
    ->name('api.events.waitlist');

// Ticket checkout API Routes
Route::prefix('tickets')->name('api.tickets.')->group(function () {
    Route::post('/quote', [\App\Http\Controllers\Api\TicketController::class, 'quote'])->name('quote');
    Route::post('/discounts/validate', [\App\Http\Controllers\Api\TicketController::class, 'validateDiscountCode'])->name('discounts.validate');
    Route::post('/purchase', [\App\Http\Controllers\Api\TicketController::class, 'purchase'])->name('purchase');
});

// Ticket account and operations API Routes (auth required)
Route::middleware('auth:sanctum')->prefix('tickets')->name('api.tickets.account.')->group(function () {
    Route::get('/attendee-profiles', [\App\Http\Controllers\Api\TicketController::class, 'attendeeProfiles'])->name('attendee-profiles');
    Route::get('/my', [\App\Http\Controllers\Api\TicketController::class, 'myTickets'])->name('my');
    Route::post('/{id}/resend', [\App\Http\Controllers\Api\TicketController::class, 'resend'])->name('resend');
    Route::post('/{id}/transfer', [\App\Http\Controllers\Api\TicketController::class, 'transfer'])->name('transfer');
    Route::get('/validate/{ticketNumber}', [\App\Http\Controllers\Api\TicketController::class, 'validateTicket'])->name('validate');
    Route::post('/check-in', [\App\Http\Controllers\Api\TicketController::class, 'checkIn'])->name('check-in');
    Route::get('/{id}', [\App\Http\Controllers\Api\TicketController::class, 'show'])->name('show');
});

// Artist Events API Routes (auth + artist role required)
Route::middleware(['auth:sanctum', 'role:artist,admin,super_admin'])->prefix('artist/events')->name('api.artist.events.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ArtistEventsController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\ArtistEventsController::class, 'store'])->name('store');
    Route::get('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'show'])->name('show');
    Route::put('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'update'])->name('update');
    Route::post('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'update'])->name('update.post');
    Route::delete('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'destroy'])->name('destroy');
    Route::get('/{id}/analytics', [\App\Http\Controllers\Api\ArtistEventsController::class, 'analytics'])->name('analytics');
    Route::get('/{id}/analytics/export', [\App\Http\Controllers\Api\ArtistEventsController::class, 'exportAnalytics'])->name('analytics.export');
    Route::post('/{id}/discount-codes', [\App\Http\Controllers\Api\ArtistEventsController::class, 'storeDiscountCode'])->name('discount-codes.store');
    Route::delete('/{id}/discount-codes/{discountId}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'deleteDiscountCode'])->name('discount-codes.destroy');
    Route::post('/{id}/staff', [\App\Http\Controllers\Api\ArtistEventsController::class, 'addStaff'])->name('staff.store');
    Route::delete('/{id}/staff/{staffId}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'removeStaff'])->name('staff.destroy');
});

Route::middleware(['auth:sanctum'])->prefix('artist/events')->name('api.artist.events.ops.')->group(function () {
    Route::get('/{id}/check-in/lookup', [\App\Http\Controllers\Api\ArtistEventsController::class, 'checkInLookup'])->name('checkin.lookup');
    Route::post('/{id}/check-in', [\App\Http\Controllers\Api\ArtistEventsController::class, 'checkInAttendee'])->name('checkin.store');
});

// ============================================================================
// Artist API Routes — Dashboard, Songs, Albums, Profile, Earnings, Analytics
// SECURED: Requires auth + artist/admin role (HIGH-5 fix)
// ============================================================================
Route::middleware(['auth:sanctum', 'role:artist,admin,super_admin'])->prefix('artist')->name('api.artist.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\ArtistApiController::class, 'dashboard'])->name('dashboard');

    // Songs CRUD
    Route::get('/songs', [\App\Http\Controllers\Api\ArtistApiController::class, 'songs'])->name('songs.index');
    Route::post('/songs', [\App\Http\Controllers\Api\ArtistApiController::class, 'storeSong'])->name('songs.store');
    Route::get('/songs/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'showSong'])->name('songs.show');
    Route::put('/songs/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'updateSong'])->name('songs.update');
    Route::delete('/songs/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'deleteSong'])->name('songs.destroy');
    Route::post('/songs/bulk-delete', [\App\Http\Controllers\Api\ArtistApiController::class, 'bulkDeleteSongs'])->name('songs.bulkDelete');
    Route::post('/songs/bulk-status', [\App\Http\Controllers\Api\ArtistApiController::class, 'bulkUpdateSongStatus'])->name('songs.bulkStatus');

    // Albums
    Route::get('/albums', [\App\Http\Controllers\Api\ArtistApiController::class, 'albums'])->name('albums.index');
    Route::post('/albums', [\App\Http\Controllers\Api\ArtistApiController::class, 'storeAlbum'])->name('albums.store');
    Route::get('/albums/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'showAlbum'])->name('albums.show');
    Route::put('/albums/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'updateAlbum'])->name('albums.update');

    // Profile
    Route::get('/profile', [\App\Http\Controllers\Api\ArtistApiController::class, 'profile'])->name('profile.show');
    Route::put('/profile', [\App\Http\Controllers\Api\ArtistApiController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/avatar', [\App\Http\Controllers\Api\ArtistApiController::class, 'uploadProfileAvatar'])->name('profile.avatar');
    Route::post('/profile/banner', [\App\Http\Controllers\Api\ArtistApiController::class, 'uploadProfileBanner'])->name('profile.banner');

    // Earnings
    Route::get('/earnings', [\App\Http\Controllers\Api\ArtistApiController::class, 'earnings'])->name('earnings.index');
    Route::get('/earnings/songs', [\App\Http\Controllers\Api\ArtistApiController::class, 'perSongEarnings'])->name('earnings.songs');
    Route::post('/earnings/withdraw', [\App\Http\Controllers\Api\ArtistApiController::class, 'withdraw'])->name('earnings.withdraw');

    // Royalty Splits
    Route::get('/royalty-splits', [\App\Http\Controllers\Api\ArtistApiController::class, 'royaltySplits'])->name('royalty-splits.index');

    // Analytics
    Route::get('/analytics', [\App\Http\Controllers\Api\ArtistApiController::class, 'analytics'])->name('analytics');

    // Referrals
    Route::get('/referrals/dashboard', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralsDashboard'])->name('referrals.dashboard');
    Route::get('/referrals/link', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralLink'])->name('referrals.link');
    Route::get('/referrals/fans', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralFans'])->name('referrals.fans');
    Route::get('/referrals/earnings', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralEarnings'])->name('referrals.earnings');
    Route::get('/referrals/promo-materials', [\App\Http\Controllers\Api\ArtistApiController::class, 'promoMaterials'])->name('referrals.promo');
    Route::post('/referrals/promo-materials/generate', [\App\Http\Controllers\Api\ArtistApiController::class, 'generatePromoMaterial'])->name('referrals.promoGenerate');
    Route::post('/referrals/share', [\App\Http\Controllers\Api\ArtistApiController::class, 'trackShare'])->name('referrals.share');
});

Route::middleware('auth:sanctum')->prefix('catalog')->name('api.catalog.')->group(function () {
    Route::post('/submissions', [\App\Http\Controllers\Api\CatalogSubmissionController::class, 'store'])->name('submissions.store');
    Route::get('/submissions', [\App\Http\Controllers\Api\CatalogSubmissionController::class, 'index'])->name('submissions.index');
    Route::get('/submissions/{submission}', [\App\Http\Controllers\Api\CatalogSubmissionController::class, 'show'])->name('submissions.show');
    Route::get('/claim-requests', [\App\Http\Controllers\Api\CatalogClaimRequestController::class, 'index'])->name('claims.index');
    Route::post('/claim-requests', [\App\Http\Controllers\Api\CatalogClaimRequestController::class, 'store'])->name('claims.store');
});

Route::get('/catalog/claimable-artists', [\App\Http\Controllers\Api\Music\ArtistController::class, 'index'])->name('api.catalog.claimable-artists');

Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/catalog')->name('api.admin.catalog.')->group(function () {
    Route::get('/claim-requests', [\App\Http\Controllers\Api\Admin\CatalogClaimRequestAdminController::class, 'index'])->name('claims.index');
    Route::post('/claim-requests/{claim}/approve', [\App\Http\Controllers\Api\Admin\CatalogClaimRequestAdminController::class, 'approve'])->name('claims.approve');
    Route::post('/claim-requests/{claim}/reject', [\App\Http\Controllers\Api\Admin\CatalogClaimRequestAdminController::class, 'reject'])->name('claims.reject');
});

// Ad tracking endpoints (no auth required for impressions)
Route::middleware('throttle:ad-tracking')->group(function () {
    Route::post('/ads/impression', [\App\Http\Controllers\Api\AdTrackingController::class, 'recordImpression']);
    Route::post('/ads/click', [\App\Http\Controllers\Api\AdTrackingController::class, 'recordClick']);
});

// Theme preference (works for both guests and authenticated users)
Route::post('/theme', [\App\Http\Controllers\ThemeController::class, 'update'])
    ->middleware('throttle:theme')
    ->name('api.theme.update');
Route::get('/theme', [\App\Http\Controllers\ThemeController::class, 'get'])->name('api.theme.get');

// Genres API endpoint for artist registration
Route::get('/genres', [\App\Http\Controllers\Api\GenreController::class, 'index']);

// Slideshow API endpoints
Route::prefix('slideshow')->name('api.slideshow.')->group(function () {
    Route::get('/{section}', [\App\Http\Controllers\Api\SlideshowController::class, 'index'])
        ->where('section', 'home|discover|radio|community|trending|channels|all')
        ->name('section');
    Route::get('/genre/{slug}', [\App\Http\Controllers\Api\SlideshowController::class, 'byGenre'])->name('genre');
    Route::get('/mood/{slug}', [\App\Http\Controllers\Api\SlideshowController::class, 'byMood'])->name('mood');
});

// Player API endpoints
Route::middleware('auth:sanctum')->prefix('player')->name('api.player.')->group(function () {
    Route::post('/update-now-playing', [\App\Http\Controllers\Api\PlayerController::class, 'updateNowPlaying'])->name('now-playing');
    Route::post('/record-play', [\App\Http\Controllers\Api\PlayerController::class, 'recordPlay'])->name('record-play');

    // Extended player controls
    Route::get('/status', [\App\Http\Controllers\Api\Player\PlayerController::class, 'getStatus'])->name('status');
    Route::post('/previous', [\App\Http\Controllers\Api\Player\PlayerController::class, 'previous'])->name('previous');
    Route::post('/next', [\App\Http\Controllers\Api\Player\PlayerController::class, 'next'])->name('next');
    Route::post('/seek', [\App\Http\Controllers\Api\Player\PlayerController::class, 'seek'])->name('seek');

    // Queue management
    Route::get('/queue', [\App\Http\Controllers\Api\Player\QueueController::class, 'getQueue'])->name('queue.index');
    Route::post('/queue', [\App\Http\Controllers\Api\Player\QueueController::class, 'addToQueue'])->name('queue.add');
    Route::delete('/queue', [\App\Http\Controllers\Api\Player\QueueController::class, 'clearQueue'])->name('queue.clear');
    Route::post('/queue/shuffle', [\App\Http\Controllers\Api\Player\QueueController::class, 'shuffleQueue'])->name('queue.shuffle');
    Route::put('/queue/reorder', [\App\Http\Controllers\Api\Player\QueueController::class, 'reorderQueue'])->name('queue.reorder');
    Route::delete('/queue/{queueItem}', [\App\Http\Controllers\Api\Player\QueueController::class, 'removeFromQueue'])->name('queue.remove');
});

// Activity Interaction API endpoints
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('settings')->group(function () {
        Route::get('/2fa', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'status']);
        Route::post('/2fa/enable', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'enable']);
        Route::post('/2fa/verify', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'verify']);
        Route::post('/2fa/disable', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'disable']);
        Route::post('/2fa/recovery-codes', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'regenerateRecoveryCodes']);
    });

    // User profile management
    Route::put('/user', [\App\Http\Controllers\Api\User\ProfileController::class, 'update'])
        ->name('api.user.update.sanctum');
    Route::get('/user/profile', [\App\Http\Controllers\Api\User\ProfileController::class, 'show'])
        ->name('api.user.profile.sanctum');
    Route::get('/user/library', [\App\Http\Controllers\Api\User\ProfileController::class, 'library'])
        ->name('api.user.library.sanctum');

    // Like/Unlike any entity
    Route::post('/like/{type}/{id}', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'toggleLike'])
        ->name('api.like.toggle');

    // Like status for any entity
    Route::get('/like/{type}/{id}/status', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'likeStatus'])
        ->name('api.like.status');

    // Bookmark/Unbookmark any entity
    Route::post('/bookmark/{type}/{id}', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'toggleBookmark'])
        ->name('api.bookmark.toggle');

    // Event interest
    Route::post('/events/{id}/interest', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'toggleEventInterest'])
        ->name('api.events.interest');
});

// Content API (Genres & Moods)
require __DIR__.'/api/content.php';

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('deprecated:2026-06-30,/api')
    ->group(function () {
        require __DIR__.'/api/v1/api.php';
    });

// Store Module API Routes (if enabled)
if (config('store.enabled', false)) {
    Route::prefix('v1/store')
        ->name('api.v1.store.')
        ->middleware('deprecated:2026-06-30,/api/store')
        ->group(function () {
            require app_path('Modules/Store/Routes/api.php');
        });
}

// The Store module provider registers /api/store routes when STORE_ENABLED is
// set at application boot. Tests enable the module later in setUp(), so we
// still load the legacy AJAX routes during test runs to preserve the /api/store
// contract without duplicating production routes.
if (app()->runningUnitTests()) {
    require __DIR__.'/api/store.php';
}

// Admin Store API — SECURED with auth + role middleware (SEC-CRIT-2 fix)
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/store')->name('admin.store.api.')->group(function () {
    // Store Management API (for admin/store dashboard)
    Route::get('/stats', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'stats'])->name('stats');
    Route::get('/products', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'products'])->name('products.index');
    Route::post('/products', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'createProduct'])->name('products.store');
    Route::put('/products/{product}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'updateProduct'])->name('products.update');
    Route::post('/products/{product}/toggle-status', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'toggleProductStatus'])->name('products.toggle');
    Route::delete('/products/{product}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'deleteProduct'])->name('products.delete');
    Route::get('/orders', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'orders'])->name('orders.index');
    Route::post('/orders/{order}/status', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'updateOrderStatus'])->name('orders.status');

    // Shop management
    Route::get('/shops', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'shops'])->name('shops.index');
    Route::post('/shops', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'createShop'])->name('shops.store');
    Route::put('/shops/{store}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'updateShop'])->name('shops.update');
    Route::post('/shops/{store}/toggle-status', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'toggleShopStatus'])->name('shops.toggle');
    Route::post('/shops/{store}/approve', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'approveShop'])->name('shops.approve');
    Route::post('/shops/{store}/suspend', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'suspendShop'])->name('shops.suspend');
    Route::post('/shops/{store}/verify', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'verifyShop'])->name('shops.verify');
    Route::post('/shops/{store}/unverify', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'unverifyShop'])->name('shops.unverify');
    Route::delete('/shops/{store}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'deleteShop'])->name('shops.delete');
    Route::get('/analytics', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'analytics'])->name('analytics');
});

Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/featured')->name('admin.featured.api.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'store'])->name('store');
    Route::post('/reorder', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'reorder'])->name('reorder');
    Route::post('/{id}/toggle', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'toggle'])->name('toggle');
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'update'])->name('update');
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'destroy'])->name('destroy');
});

// Cross-Module Notification API Routes
Route::middleware('auth:sanctum')->prefix('notifications')->name('api.notifications.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\NotificationController::class, 'index'])->name('index');
    Route::get('/unread-counts', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCounts'])->name('unread-counts');
    Route::get('/recent', [\App\Http\Controllers\Api\NotificationController::class, 'recent'])->name('recent');
    Route::get('/settings', [\App\Http\Controllers\Api\NotificationController::class, 'settings'])->name('settings');
    Route::put('/settings', [\App\Http\Controllers\Api\NotificationController::class, 'updateSettings'])->name('update-settings');
    Route::post('/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
    Route::post('/{notification}/mark-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead'])->name('mark-read');
    Route::delete('/{notification}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy'])->name('delete');

    // Admin only routes
    Route::middleware('role:admin,super_admin')->group(function () {
        // Removed send-test route - use proper notification testing
        Route::get('/analytics', [\App\Http\Controllers\Api\NotificationController::class, 'analytics'])->name('analytics');
        Route::get('/health', [\App\Http\Controllers\Api\NotificationController::class, 'health'])->name('health');
        Route::post('/preview', [\App\Http\Controllers\Api\NotificationController::class, 'preview'])->name('preview');
    });
});

// Device Token Management (Push Notifications)
Route::middleware('auth:sanctum')->prefix('device-tokens')->name('api.device-tokens.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\DeviceTokenController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\DeviceTokenController::class, 'store'])->name('store');
    Route::delete('/{id}', [\App\Http\Controllers\Api\DeviceTokenController::class, 'destroy'])->name('destroy');
    Route::post('/deactivate-all', [\App\Http\Controllers\Api\DeviceTokenController::class, 'deactivateAll'])->name('deactivate-all');
});

// Mobile Content API Routes (for sliders)
Route::prefix('mobile')->name('api.mobile.')->group(function () {
    Route::get('/trending/songs', [\App\Http\Controllers\Api\MobileContentController::class, 'trendingSongs'])->name('trending.songs');
    Route::get('/popular/artists', [\App\Http\Controllers\Api\MobileContentController::class, 'popularArtists'])->name('popular.artists');
    Route::get('/popular/albums', [\App\Http\Controllers\Api\MobileContentController::class, 'popularAlbums'])->name('popular.albums');
    Route::get('/radio/stations', [\App\Http\Controllers\Api\MobileContentController::class, 'radioStations'])->name('radio.stations');
    Route::get('/featured/charts', [\App\Http\Controllers\Api\MobileContentController::class, 'featuredCharts'])->name('featured.charts');
});

// Mobile App API Routes (React Native)
require __DIR__.'/api/mobile.php';

// Payment API Routes — artist-payout RESTRICTED to admin only (SEC-CRIT-3 fix)
Route::middleware('auth:sanctum')->prefix('payments')->name('api.payments.')->group(function () {
    Route::post('/subscription', [\App\Http\Controllers\Api\PaymentController::class, 'processSubscription'])->name('subscription');
    Route::post('/{payment}/refund', [\App\Http\Controllers\Api\PaymentController::class, 'refund'])->name('refund');
    Route::post('/artist-payout', [\App\Http\Controllers\Api\PaymentController::class, 'artistPayout'])->name('artist-payout')->middleware('role:admin,super_admin');
});

// Payout API Routes
Route::middleware('auth:sanctum')->prefix('payouts')->name('api.payouts.')->group(function () {
    Route::post('/request', [\App\Http\Controllers\Api\PayoutController::class, 'requestPayout'])->name('request');
});

// Subscription API Routes
Route::get('/subscription-plans', [\App\Http\Controllers\Api\SubscriptionController::class, 'plans'])->name('api.subscription-plans');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/subscription', [\App\Http\Controllers\Api\SubscriptionController::class, 'current'])->name('api.user.subscription');
    Route::get('/user/subscription/history', [\App\Http\Controllers\Api\SubscriptionController::class, 'history'])->name('api.user.subscription.history');
    Route::post('/subscriptions/subscribe', [\App\Http\Controllers\Api\SubscriptionController::class, 'subscribe'])->name('api.subscriptions.subscribe');
    Route::post('/subscriptions/change-plan', [\App\Http\Controllers\Api\SubscriptionController::class, 'changePlan'])->name('api.subscriptions.change-plan');
    Route::post('/subscriptions/toggle-auto-renew', [\App\Http\Controllers\Api\SubscriptionController::class, 'toggleAutoRenew'])->name('api.subscriptions.toggle-auto-renew');
});

Route::middleware('auth:sanctum')->prefix('subscriptions')->name('api.subscriptions.')->group(function () {
    Route::post('/{subscription}/cancel', [\App\Http\Controllers\Api\SubscriptionController::class, 'cancel'])->name('cancel');
    Route::post('/{subscription}/extend', [\App\Http\Controllers\Api\SubscriptionController::class, 'extend'])->name('extend')->middleware('role:admin,super_admin');
});

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

    // Platform analytics overview used by the Next admin dashboard.
    Route::get('/analytics', [\App\Http\Controllers\Api\Admin\AdminAnalyticsController::class, 'overview'])->name('analytics.overview');

    // API Usage Analytics
    Route::get('/analytics/api-usage', [\App\Http\Controllers\Api\Admin\ApiAnalyticsController::class, 'dashboard'])->name('analytics.api-usage');
    Route::get('/analytics/top-users', [\App\Http\Controllers\Api\Admin\ApiAnalyticsController::class, 'topUsers'])->name('analytics.top-users');
    Route::get('/analytics/api-usage/top-users', [\App\Http\Controllers\Api\Admin\ApiAnalyticsController::class, 'topUsers'])->name('analytics.api-usage.top-users');
});

Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin')->name('api.admin.')->group(function () {
    // Base admin route
    Route::get('/', \App\Http\Controllers\Api\Admin\AdminIndexController::class)->name('index');

    // Settings API
    Route::get('/settings', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'update'])->name('settings.update');

    // Audit logs and feature operations surfaced in the Next admin shell.
    Route::get('/audit-logs', [\App\Http\Controllers\Api\Admin\AdminAuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/feature-flags', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'index'])->name('feature-flags.index');
    Route::post('/feature-flags', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'store'])->name('feature-flags.store');
    Route::put('/feature-flags/{id}', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'update'])->name('feature-flags.update');
    Route::delete('/feature-flags/{id}', [\App\Http\Controllers\Api\Admin\AdminFeatureFlagController::class, 'destroy'])->name('feature-flags.destroy');

    // Users API
    Route::get('/users', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'index'])->name('users.index');
    Route::get('/users/statistics', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'statistics'])->name('users.statistics');
    Route::get('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'show'])->name('users.show');
    Route::post('/users', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'store'])->name('users.store');
    Route::put('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/ban', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'ban'])->name('users.ban');
    Route::post('/users/{id}/activate', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'activate'])->name('users.activate');
    Route::delete('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'destroy'])->name('users.destroy');

    // Events API
    Route::get('/events/stats', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'stats'])->name('events.stats');
    Route::get('/events', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'index'])->name('events.index');
    Route::get('/events/{id}', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'show'])->name('events.show');
    Route::post('/events', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'store'])->name('events.store');
    Route::put('/events/{id}', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'update'])->name('events.update');
    Route::delete('/events/{id}', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'destroy'])->name('events.destroy');
    Route::post('/events/{id}/publish', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'publish'])->name('events.publish');
    Route::post('/events/{id}/toggle-featured', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'toggleFeatured'])->name('events.toggle-featured');
    Route::get('/events/{id}/analytics', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'analytics'])->name('events.analytics');
    Route::get('/events/{id}/analytics/export', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'exportAnalytics'])->name('events.analytics.export');
    Route::get('/events/{id}/attendees', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'attendees'])->name('events.attendees');
    Route::get('/events/{id}/registrations', [\App\Http\Controllers\Api\Admin\EventsApiController::class, 'registrations'])->name('events.registrations');

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

    // Forums API — full CRUD
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

    // Polls API — full CRUD
    Route::get('/polls/stats', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'stats'])->name('polls.stats');
    Route::get('/polls', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'index'])->name('polls.index');
    Route::post('/polls', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'store'])->name('polls.store');
    Route::get('/polls/{id}', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'show'])->name('polls.show');
    Route::put('/polls/{id}', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'update'])->name('polls.update');
    Route::delete('/polls/{id}', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'destroy'])->name('polls.destroy');
    Route::post('/polls/{id}/close', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'close'])->name('polls.close');
    Route::post('/polls/{id}/reopen', [\App\Http\Controllers\Api\Admin\PollsApiController::class, 'reopen'])->name('polls.reopen');

    // SACCO API
    Route::get('/sacco/stats', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'stats'])->name('sacco.stats');
    Route::get('/sacco/members', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'members'])->name('sacco.members');
    Route::get('/sacco/members/{id}', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'showMember'])->name('sacco.members.show');
    Route::get('/sacco/members/{id}/transactions', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'memberTransactions'])->name('sacco.members.transactions');
    Route::get('/sacco/members/{id}/loans', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'memberLoans'])->name('sacco.members.loans');
    Route::get('/sacco/loans', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'loans'])->name('sacco.loans');
    Route::get('/sacco/loans/{id}', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'showLoan'])->name('sacco.loans.show');
    Route::post('/sacco/loans/{id}/approve', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'approveLoan'])->name('sacco.loans.approve');
    Route::post('/sacco/loans/{id}/reject', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'rejectLoan'])->name('sacco.loans.reject');
    Route::post('/sacco/loans/{id}/disburse', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'disburseLoan'])->name('sacco.loans.disburse');
    Route::get('/sacco/loans/{id}/repayments', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'loanRepayments'])->name('sacco.loans.repayments');
    Route::get('/sacco/transactions', [\App\Http\Controllers\Api\Admin\SaccoApiController::class, 'savingsTransactions'])->name('sacco.transactions');

    // SACCO Board Meetings & Members (Admin CRUD)
    Route::get('/sacco/board-members', [\App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController::class, 'boardMembers'])->name('sacco.board-members');
    Route::get('/sacco/board-meetings', [\App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController::class, 'index'])->name('sacco.board-meetings.index');
    Route::post('/sacco/board-meetings', [\App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController::class, 'store'])->name('sacco.board-meetings.store');
    Route::get('/sacco/board-meetings/{id}', [\App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController::class, 'show'])->name('sacco.board-meetings.show');
    Route::put('/sacco/board-meetings/{id}', [\App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController::class, 'update'])->name('sacco.board-meetings.update');
    Route::delete('/sacco/board-meetings/{id}', [\App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController::class, 'destroy'])->name('sacco.board-meetings.destroy');
    Route::get('/sacco/meetings', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'meetings'])->name('sacco.meetings.index');
    Route::post('/sacco/meetings', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'storeMeeting'])->name('sacco.meetings.store');
    Route::get('/sacco/meetings/attendance-summary', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'attendanceSummary'])->name('sacco.meetings.attendance-summary');
    Route::get('/sacco/meetings/{meeting}', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'showMeeting'])->name('sacco.meetings.show');
    Route::put('/sacco/meetings/{meeting}', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'updateMeeting'])->name('sacco.meetings.update');
    Route::delete('/sacco/meetings/{meeting}', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'destroyMeeting'])->name('sacco.meetings.destroy');
    Route::get('/sacco/meetings/{meeting}/attendance', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'attendance'])->name('sacco.meetings.attendance');
    Route::post('/sacco/meetings/{meeting}/attendance', [\App\Http\Controllers\Api\Admin\SaccoGovernanceController::class, 'markAttendance'])->name('sacco.meetings.attendance.mark');

    // Songs API
    Route::get('/songs/statistics', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'statistics'])->name('songs.statistics');
    Route::post('/songs/bulk-approve', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'bulkApprove'])->name('songs.bulk-approve');
    Route::post('/songs/bulk-reject', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'bulkReject'])->name('songs.bulk-reject');
    Route::get('/songs', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'index'])->name('songs.index');
    Route::get('/songs/{id}', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'show'])->name('songs.show');
    Route::post('/songs', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'store'])->name('songs.store');
    Route::put('/songs/{id}', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'update'])->name('songs.update');
    Route::delete('/songs/{id}', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'destroy'])->name('songs.destroy');
    Route::post('/songs/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'toggleStatus'])->name('songs.toggle-status');
    Route::post('/songs/{id}/toggle-featured', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'toggleFeatured'])->name('songs.toggle-featured');
    Route::get('/songs/{id}/play-history', [\App\Http\Controllers\Api\Admin\SongsApiController::class, 'playHistory'])->name('songs.play-history');

    // Albums API
    Route::get('/albums/statistics', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'statistics'])->name('albums.statistics');
    Route::get('/albums', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'index'])->name('albums.index');
    Route::post('/albums', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'store'])->name('albums.store');
    Route::get('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'show'])->name('albums.show');
    Route::put('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'update'])->name('albums.update');
    Route::post('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'update'])->name('albums.update.post');
    Route::delete('/albums/{id}', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'destroy'])->name('albums.destroy');
    Route::post('/albums/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\AdminAlbumsController::class, 'toggleStatus'])->name('albums.toggle-status');

    // Awards API — full CRUD
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
    Route::get('/roles/permissions', [\App\Http\Controllers\Api\Admin\RoleController::class, 'permissions'])->name('roles.permissions');
    Route::get('/roles/{role}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'show'])->name('roles.show');
    Route::post('/roles', [\App\Http\Controllers\Api\Admin\RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles/{role}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [\App\Http\Controllers\Api\Admin\RoleController::class, 'destroy'])->name('roles.destroy');
    Route::post('/roles/assign', [\App\Http\Controllers\Api\Admin\RoleController::class, 'assignToUser'])->name('roles.assign');
    Route::post('/roles/remove', [\App\Http\Controllers\Api\Admin\RoleController::class, 'removeFromUser'])->name('roles.remove');

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

    // Podcasts API — full CRUD + moderation
    Route::get('/podcasts/stats', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'stats'])->name('podcasts.stats');
    Route::get('/podcasts/categories', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'categories'])->name('podcasts.categories');
    Route::get('/podcasts', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'index'])->name('podcasts.index');
    Route::get('/podcasts/{id}', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'show'])->name('podcasts.show');
    Route::post('/podcasts', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'store'])->name('podcasts.store');
    Route::put('/podcasts/{id}', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'update'])->name('podcasts.update');
    Route::delete('/podcasts/{id}', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'destroy'])->name('podcasts.destroy');
    Route::post('/podcasts/{id}/approve', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'approve'])->name('podcasts.approve');
    Route::post('/podcasts/{id}/suspend', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'suspend'])->name('podcasts.suspend');
    Route::get('/podcasts/{$id}/episodes', [\App\Http\Controllers\Api\Admin\AdminPodcastsController::class, 'episodes'])->name('podcasts.episodes');

    // Genres API — full CRUD
    Route::get('/genres', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'index'])->name('genres.index');
    Route::post('/genres', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'store'])->name('genres.store');
    Route::get('/genres/{id}', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'show'])->name('genres.show');
    Route::put('/genres/{id}', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'update'])->name('genres.update');
    Route::delete('/genres/{id}', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'destroy'])->name('genres.destroy');
    Route::post('/genres/{id}/toggle-active', [\App\Http\Controllers\Api\Admin\AdminGenreController::class, 'toggleActive'])->name('genres.toggle-active');
    Route::get('/reports/stats', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'stats'])->name('reports.stats');
    Route::get('/reports/export', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'exportReports'])->name('reports.export');
    Route::get('/reports/streaming-payouts', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'streamingPayouts'])->name('reports.streaming-payouts');
    Route::get('/reports/streaming-payouts/export', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'exportStreamingPayouts'])->name('reports.streaming-payouts.export');
    Route::get('/reports', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'index'])->name('reports.index');
    Route::post('/reports/{report}/status', [\App\Http\Controllers\Api\Admin\AdminReportsController::class, 'updateStatus'])->name('reports.status');
    Route::get('/system/health', [\App\Http\Controllers\Api\Admin\AdminSystemController::class, 'health'])->name('system.health');
    Route::get('/system/tests', [\App\Http\Controllers\Api\Admin\AdminSystemController::class, 'tests'])->name('system.tests');
    Route::post('/system/actions', [\App\Http\Controllers\Api\Admin\AdminSystemController::class, 'action'])->name('system.actions');
});

// Payment Webhooks (Public - no auth required, rate limited)
Route::middleware('webhook.rate_limit')->group(function () {
    Route::post('/webhooks/payment/{provider}', [\App\Http\Controllers\Api\PaymentController::class, 'webhook'])->name('api.webhooks.payment');
    Route::post('/payments/webhook', [\App\Http\Controllers\Api\PaymentController::class, 'webhook'])->name('api.payments.webhook');
    Route::post('/webhooks/mobile-money', [\App\Http\Controllers\Api\MobileMoneyWebhookController::class, 'handle'])->name('webhooks.mobile-money');
});

// ISRC Generation Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/songs/{song}/generate-isrc', [\App\Http\Controllers\Api\ISRCController::class, 'generateForSong'])->name('api.isrc.generate');
    Route::post('/albums/{album}/generate-isrc', [\App\Http\Controllers\Api\ISRCController::class, 'generateForAlbum'])->name('api.isrc.generate-album');
    Route::post('/albums/{album}/generate-isrc-batch', [\App\Http\Controllers\Api\ISRCController::class, 'generateBatchForAlbum'])->name('api.isrc.generate-batch');
    Route::post('/isrc/{isrc}/register', [\App\Http\Controllers\Api\ISRCController::class, 'register'])->name('api.isrc.register');
    Route::post('/isrc/{isrc}/clearance', [\App\Http\Controllers\Api\ISRCController::class, 'clearance'])->name('api.isrc.clearance');
    Route::post('/isrc/{isrc}/clear-for-distribution', [\App\Http\Controllers\Api\ISRCController::class, 'clearance'])->name('api.isrc.clear-distribution');
    Route::post('/isrc/bulk', [\App\Http\Controllers\Api\ISRCController::class, 'bulkOperation'])->name('api.isrc.bulk');
    Route::post('/isrc/bulk-register', [\App\Http\Controllers\Api\ISRCController::class, 'bulkRegister'])->name('api.isrc.bulk-register');
    Route::post('/isrc/bulk-clear-distribution', [\App\Http\Controllers\Api\ISRCController::class, 'bulkClearDistribution'])->name('api.isrc.bulk-clear-distribution');
    Route::get('/isrc', [\App\Http\Controllers\Api\ISRCController::class, 'index'])->name('api.isrc.index');
    Route::get('/isrc/search', [\App\Http\Controllers\Api\ISRCController::class, 'search'])->name('api.isrc.search');
    Route::get('/isrc/export', [\App\Http\Controllers\Api\ISRCController::class, 'export'])->name('api.isrc.export');
    Route::post('/isrc/check-duplicate', [\App\Http\Controllers\Api\ISRCController::class, 'checkDuplicate'])->name('api.isrc.check-duplicate');
    Route::get('/isrc/analytics', [\App\Http\Controllers\Api\ISRCController::class, 'analytics'])->name('api.isrc.analytics');
});

// Song Management API Routes
// Note: Artist song CRUD (create/update/delete) is handled by ArtistApiController at /api/artist/songs/*
Route::middleware('auth:sanctum')->prefix('songs')->name('api.songs.')->group(function () {
    // Distribution
    Route::post('/{song}/distribute', [\App\Http\Controllers\DistributionController::class, 'submitSongDistribution'])->name('distribute');
    Route::get('/{song}/distributions', [\App\Http\Controllers\DistributionController::class, 'getSongDistributions'])->name('distributions');
    Route::post('/{song}/distributions/{distribution}/remove', [\App\Http\Controllers\DistributionController::class, 'requestRemoval'])->name('distribution.remove');
});

Route::middleware('auth:sanctum')->prefix('albums')->name('api.albums.')->group(function () {
    Route::post('/{album}/distribute', [\App\Http\Controllers\DistributionController::class, 'distributeAlbum'])->name('distribute');
});

Route::middleware('auth:sanctum')->prefix('distributions')->name('api.distributions.')->group(function () {
    Route::post('/bulk-submit', [\App\Http\Controllers\DistributionController::class, 'bulkSubmit'])->name('bulk-submit');
    Route::get('/{distribution}/status', [\App\Http\Controllers\DistributionController::class, 'getStatus'])->name('status');
    Route::post('/{distribution}/remove', [\App\Http\Controllers\DistributionController::class, 'requestRemoval'])->name('remove');
    Route::post('/{distribution}/retry', [\App\Http\Controllers\DistributionController::class, 'retryDistribution'])->name('retry');
    Route::get('/{distribution}/royalty-report', [\App\Http\Controllers\DistributionController::class, 'getRoyaltyReport'])->name('royalty-report');
});

Route::middleware('auth:sanctum')->prefix('artist')->name('api.artist.')->group(function () {
    Route::get('/distribution-analytics', [\App\Http\Controllers\DistributionController::class, 'getAnalytics'])->name('distribution-analytics');

    // Artist Application Routes
    Route::get('/application-status', [\App\Http\Controllers\Api\ArtistApplicationApiController::class, 'status'])->name('application-status');
    Route::post('/apply', [\App\Http\Controllers\Api\ArtistApplicationApiController::class, 'store'])->name('apply');
});

Route::prefix('webhooks/distribution')->middleware('webhook.rate_limit')->name('api.webhooks.distribution.')->group(function () {
    Route::post('/{platform}', [\App\Http\Controllers\DistributionWebhookController::class, 'handle'])->name('handle');
});

// Admin Distribution Performance
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])
    ->prefix('admin/distribution-performance')
    ->name('api.admin.distribution.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\DistributionPerformanceController::class, 'performance'])->name('performance');
    });

// ============================================================================
// PODCAST API ROUTES (Consolidated from routes/podcast-api.php)
// ============================================================================
// Public API endpoints
Route::prefix('podcasts')->name('api.podcast.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'index']);
    Route::get('/{podcast:uuid}', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'show']);
    Route::get('/{podcast:uuid}/episodes', [\App\Http\Controllers\Api\Podcast\EpisodeApiController::class, 'index']);
    Route::get('/{podcast:uuid}/rss', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'rssFeed'])->name('rss');

    // Authenticated endpoints
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/{podcast:uuid}/subscribe', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'subscribe']);
        Route::delete('/{podcast:uuid}/unsubscribe', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'unsubscribe']);
    });
});

// Podcast episodes
Route::get('/episodes/{episode:uuid}', [\App\Http\Controllers\Api\Podcast\EpisodeApiController::class, 'show']);

// Podcast search & discovery
Route::get('/podcasts-search', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'search']);
Route::get('/podcasts-trending', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'trending']);
Route::get('/podcast-categories', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'categories']);

// Podcast player & analytics (authenticated)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/episodes/{episode:uuid}/play', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'play']);
    Route::post('/episodes/{episode:uuid}/progress', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'updateProgress']);
    Route::post('/episodes/{episode:uuid}/complete', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'markComplete']);

    // Episode analytics tracking
    Route::post('/episodes/{episode:uuid}/track-listen', [\App\Http\Controllers\Api\Podcast\AnalyticsApiController::class, 'trackListen']);
    Route::post('/episodes/{episode:uuid}/track-download', [\App\Http\Controllers\Api\Podcast\AnalyticsApiController::class, 'trackDownload']);
    Route::post('/episodes/{episode:uuid}/track-skip', [\App\Http\Controllers\Api\Podcast\AnalyticsApiController::class, 'trackSkip']);

    Route::get('/my-podcast-subscriptions', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'mySubscriptions']);
    Route::get('/my-listening-queue', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'listeningQueue']);
    Route::get('/my-recent-podcasts', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'recentlyPlayed']);
});

// SACCO API Routes
Route::prefix('sacco')
    ->middleware(['auth:sanctum'])
    ->name('api.sacco.')
    ->group(function () {
        // Public-to-authenticated membership entrypoints
        Route::get('membership', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'myMembership'])->name('membership');
        Route::post('join', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'join'])->name('join');

        Route::middleware('sacco.member.api')->group(function () {
            // Base sacco route
            Route::get('/', \App\Http\Controllers\Api\Sacco\SaccoIndexController::class)->name('index');
            Route::get('dashboard', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'dashboard'])->name('dashboard');
            Route::get('profile', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'profile'])->name('profile');
            Route::get('transactions', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'memberTransactions'])->name('transactions.index');

            // Membership
            Route::get('members', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'index'])->name('members.index');
            Route::post('members', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'store'])->name('members.store');
            Route::get('members/{member}', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'show'])->name('members.show');
            Route::put('members/{member}', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'update'])->name('members.update');
            Route::patch('members/{member}/status', [\App\Http\Controllers\Api\Sacco\SaccoMembershipController::class, 'updateStatus'])->name('members.status');

            // Savings
            Route::get('savings', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'summary'])->name('savings.summary');
            Route::prefix('savings')->name('savings.')->group(function () {
                Route::post('accounts', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'openAccount'])->name('accounts.open');
                Route::post('deposit', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'deposit'])->name('deposit');
                Route::post('withdraw', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'withdraw'])->name('withdraw');
                Route::get('accounts/{account}', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'show'])->name('accounts.show');
                Route::get('transactions/{account}', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'transactions'])->name('transactions');
                Route::get('balance/{account}', [\App\Http\Controllers\Api\Sacco\SaccoSavingsController::class, 'balance'])->name('balance');
            });

            // Loans
            Route::get('loan-products', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'products'])->name('loan-products.index');
            Route::prefix('loans')->name('loans.')->group(function () {
                Route::get('', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'myLoans'])->name('index');
                Route::get('guarantors', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'guarantors'])->name('guarantors');
                Route::post('apply', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'apply'])->name('apply');
                Route::get('eligibility', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'eligibility'])->name('eligibility');
                Route::post('calculate-schedule', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'calculateSchedule'])->name('calculate-schedule');
                Route::post('{loan}/approve', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'approve'])->name('approve');
                Route::post('{loan}/disburse', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'disburse'])->name('disburse');
                Route::post('{loan}/repay', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'repay'])->name('repay');
                Route::post('{loan}/pay', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'repay'])->name('pay');
                Route::get('{loan}', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'show'])->name('show');
                Route::get('member/{member}', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'memberLoans'])->name('member');
                Route::get('{loan}/schedule', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'schedule'])->name('schedule');
                Route::get('{loan}/balance', [\App\Http\Controllers\Api\Sacco\SaccoLoanController::class, 'balance'])->name('balance');
            });

            // Shares
            Route::get('shares', [\App\Http\Controllers\Api\Sacco\SaccoSharesController::class, 'myShares'])->name('shares.self');
            Route::prefix('shares')->name('shares.')->group(function () {
                Route::post('purchase', [\App\Http\Controllers\Api\Sacco\SaccoSharesController::class, 'purchase'])->name('purchase');
                Route::post('buy', [\App\Http\Controllers\Api\Sacco\SaccoSharesController::class, 'purchase'])->name('buy');
                Route::post('transfer', [\App\Http\Controllers\Api\Sacco\SaccoSharesController::class, 'transfer'])->name('transfer');
                Route::get('member/{member}', [\App\Http\Controllers\Api\Sacco\SaccoSharesController::class, 'memberShares'])->name('member');
                Route::get('value', [\App\Http\Controllers\Api\Sacco\SaccoSharesController::class, 'currentValue'])->name('value');
            });

            // Meetings
            Route::get('meetings', [\App\Http\Controllers\Api\Sacco\SaccoMeetingsController::class, 'index'])->name('meetings.index');
            Route::get('meetings/{meeting}', [\App\Http\Controllers\Api\Sacco\SaccoMeetingsController::class, 'show'])->name('meetings.show');
            Route::post('meetings/{meeting}/rsvp', [\App\Http\Controllers\Api\Sacco\SaccoMeetingsController::class, 'rsvp'])->name('meetings.rsvp');
            Route::get('notifications', [\App\Http\Controllers\Api\Sacco\SaccoNotificationsController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [\App\Http\Controllers\Api\Sacco\SaccoNotificationsController::class, 'markAllRead'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [\App\Http\Controllers\Api\Sacco\SaccoNotificationsController::class, 'markRead'])->name('notifications.read');

            // Goals (savings goals)
            Route::prefix('goals')->name('goals.')->group(function () {
                Route::get('', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'index'])->name('index');
                Route::post('', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'store'])->name('store');
                Route::get('{goal}', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'show'])->name('show');
                Route::put('{goal}', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'update'])->name('update');
                Route::delete('{goal}', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'destroy'])->name('destroy');
                Route::post('{goal}/deposit', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'deposit'])->name('deposit');
                Route::post('{goal}/convert-credits', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'convertCredits'])->name('convert-credits');
                Route::post('{goal}/auto-save', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'autoSave'])->name('auto-save');
                Route::get('{goal}/transactions', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'transactions'])->name('transactions');
                Route::get('{goal}/funding-options', [\App\Http\Controllers\Api\Sacco\SaccoGoalsController::class, 'fundingOptions'])->name('funding-options');
            });

            // Reports
            Route::prefix('reports')->name('reports.')->group(function () {
                Route::get('membership', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'membership'])->name('membership');
                Route::get('loans', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'loans'])->name('loans');
                Route::get('savings', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'savings'])->name('savings');
                Route::get('shares', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'shares'])->name('shares');
                Route::get('financial', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'financial'])->name('financial');
                Route::get('member/{member}', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'memberStatement'])->name('member');
                Route::get('overdue', [\App\Http\Controllers\Api\Sacco\SaccoReportsController::class, 'overdue'])->name('overdue');
            });

            // Analytics
            Route::prefix('analytics')->name('analytics.')->group(function () {
                Route::get('dashboard', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'dashboard'])->name('dashboard');
                Route::get('trends/membership', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'membershipTrends'])->name('trends.membership');
                Route::get('performance/loans', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'loanPerformance'])->name('performance.loans');
                Route::get('savings', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'savings'])->name('savings');
                Route::get('repayments', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'repayments'])->name('repayments');
                Route::get('portfolio', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'portfolio'])->name('portfolio');
                Route::get('activity', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'activity'])->name('activity');
                Route::get('top-performers', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'topPerformers'])->name('top-performers');
                Route::get('risk', [\App\Http\Controllers\Api\Sacco\SaccoAnalyticsController::class, 'risk'])->name('risk');
            });
        });
    });

/*
|--------------------------------------------------------------------------
| Feed API Routes
|--------------------------------------------------------------------------
|
| These routes use the FeedItem model and FeedService for the
| unified discovery feed at tesotunes.com/edula
|
*/
Route::prefix('feed')->name('api.feed.')->group(function () {
    // Public feed endpoints (guests can browse)
    Route::get('/', [\App\Http\Controllers\Api\FeedController::class, 'index'])->name('index');
    Route::get('/for-you', [\App\Http\Controllers\Api\FeedController::class, 'forYou'])->name('for-you');
    Route::get('/discover', [\App\Http\Controllers\Api\FeedController::class, 'discover'])->name('discover');
    Route::get('/module/{module}', [\App\Http\Controllers\Api\FeedController::class, 'module'])->name('module');
    Route::get('/tabs', [\App\Http\Controllers\Api\FeedController::class, 'tabs'])->name('tabs');
    Route::get('/trending', [\App\Http\Controllers\Api\FeedController::class, 'trending'])->name('trending');

    // Authenticated feed endpoints (MUST be before /{uuid} to avoid route conflicts)
    Route::middleware('auth:sanctum')->group(function () {
        // Personalized feeds
        Route::get('/following', [\App\Http\Controllers\Api\FeedController::class, 'following'])->name('following');
        Route::get('/saved', [\App\Http\Controllers\Api\FeedController::class, 'saved'])->name('saved');

        // Interaction endpoints
        Route::post('/{uuid}/not-interested', [\App\Http\Controllers\Api\FeedController::class, 'notInterested'])->name('not-interested');
        Route::post('/{uuid}/hide', [\App\Http\Controllers\Api\FeedController::class, 'hide'])->name('hide');
        Route::post('/{uuid}/save', [\App\Http\Controllers\Api\FeedController::class, 'save'])->name('save');
        Route::delete('/{uuid}/save', [\App\Http\Controllers\Api\FeedController::class, 'unsave'])->name('unsave');

        // Analytics tracking
        Route::post('/{uuid}/click', [\App\Http\Controllers\Api\FeedController::class, 'trackClick'])->name('track-click');
        Route::post('/{uuid}/engage', [\App\Http\Controllers\Api\FeedController::class, 'trackEngagement'])->name('track-engagement');

        // Utility endpoints
        Route::post('/refresh', [\App\Http\Controllers\Api\FeedController::class, 'refresh'])->name('refresh');
        Route::get('/preferences', [\App\Http\Controllers\Api\FeedController::class, 'preferences'])->name('preferences');
        Route::put('/preferences', [\App\Http\Controllers\Api\FeedController::class, 'updatePreferences'])->name('update-preferences');
    });

    // Single item view (MUST be after named routes like /following, /saved)
    Route::get('/{uuid}', [\App\Http\Controllers\Api\FeedController::class, 'show'])->name('show');
});

// ============================================================================
// USER CREDITS ROUTES
// ============================================================================
Route::prefix('credits')->middleware('auth:sanctum')->name('api.credits.')->group(function () {
    Route::get('/balance', [\App\Http\Controllers\Api\User\CreditController::class, 'balance'])->name('balance');
    Route::get('/dashboard', [\App\Http\Controllers\Api\User\CreditController::class, 'dashboard'])->name('dashboard');
    Route::get('/transactions', [\App\Http\Controllers\Api\User\CreditController::class, 'transactions'])->name('transactions');
    Route::post('/purchase', [\App\Http\Controllers\Api\User\CreditController::class, 'purchase'])->name('purchase');
    Route::post('/exchange', [\App\Http\Controllers\Api\User\CreditController::class, 'exchange'])->name('exchange');
    Route::post('/claim-daily-bonus', [\App\Http\Controllers\Api\User\CreditController::class, 'claimDailyBonus'])->name('claim-daily-bonus');
    Route::post('/transfer', [\App\Http\Controllers\Api\User\CreditController::class, 'transfer'])->name('transfer');
    Route::get('/promotions', [\App\Http\Controllers\Api\User\CreditController::class, 'promotions'])->name('promotions');
    Route::post('/promotions/{promotion}/participate', [\App\Http\Controllers\Api\User\CreditController::class, 'participateInPromotion'])->name('promotions.participate');
});

// REMOVED: test-upload debug endpoint — security hardening

// File Upload API Routes (authenticated)
Route::middleware('auth:sanctum')->prefix('uploads')->name('api.uploads.')->group(function () {
    Route::post('/audio', [\App\Http\Controllers\Api\Upload\FileController::class, 'uploadAudio'])->name('audio');
    Route::post('/image', [\App\Http\Controllers\Api\Upload\FileController::class, 'uploadImage'])->name('image');
    Route::post('/avatar', [\App\Http\Controllers\Api\Upload\FileController::class, 'uploadAvatar'])->name('avatar');
});
