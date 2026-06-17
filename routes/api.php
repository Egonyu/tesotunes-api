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

// KYC API Routes (identity verification: status, document upload, admin review)
require __DIR__.'/api/kyc.php';

// Account capability routes (artist/seller/organizer/promoter/label grants)
require __DIR__.'/api/capabilities.php';

// Development-only routes (never loaded in production)
if (app()->environment('local', 'testing')) {
    require __DIR__.'/api/dev.php';
}

// Music API Routes
require __DIR__.'/api/music.php';

// Engagement API Routes
require __DIR__.'/api/engagement.php';

// Legal Pages API Routes (Terms, Privacy, Policies)
require __DIR__.'/api/legal-pages.php';

// Payment API Routes
require __DIR__.'/api/payment.php';

// Webhook API Routes (ZengaPay, etc.)
require __DIR__.'/api/webhooks.php';

// Unified account dashboard (all users)
require __DIR__.'/api/dashboard.php';

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
Route::get('/homepage', [\App\Http\Controllers\Api\HomepageController::class, 'index'])->name('api.homepage');

// Events & Tickets API Routes
require app_path('Modules/Events/Routes/api.php');

// Artist API Routes — Dashboard, Songs, Albums, Profile, Earnings, Analytics, Catalog
require __DIR__.'/api/artist.php';

// Discovery helpers — ad tracking, theme, platform settings, genres, slideshow
require __DIR__.'/api/discovery.php';

// Player API Routes — playback controls and queue management
require __DIR__.'/api/player.php';

// User settings, profile management, and activity interactions
require __DIR__.'/api/user.php';

// Content API (Genres & Moods)
require __DIR__.'/api/content.php';

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('deprecated:2026-06-30,/api')
    ->group(function () {
        require __DIR__.'/api/v1/api.php';
    });

// The /api/v1/store mount was retired ahead of its 2026-06-30 sunset:
// web reads /api/store/* and the mobile app was verified clean of v1/store.

// The Store module provider registers /api/store routes when STORE_ENABLED is
// set at application boot. Tests enable the module later in setUp(), so we
// still load the legacy AJAX routes during test runs to preserve the /api/store
// contract without duplicating production routes.
if (app()->runningUnitTests()) {
    require __DIR__.'/api/store.php';
}

// Store module promotions routes (public browse + authenticated seller/buyer + admin)
require __DIR__.'/api/promotions.php';

// Cross-module notifications and device token management
require __DIR__.'/api/notifications.php';

// Support / contact-the-team messages (delivered to admins as notifications)
require __DIR__.'/api/support.php';

// SACCO Module API Routes
require __DIR__.'/api/sacco.php';

// Feed API Routes (Edula discovery feed)
require __DIR__.'/api/feed.php';

// User Credits Routes
require __DIR__.'/api/credits.php';

// Subscription, payout, and core payment action routes
require __DIR__.'/api/subscriptions.php';

// File Upload API Routes
require __DIR__.'/api/uploads.php';

// Admin API Routes (dashboard, users, content moderation, observability)
require __DIR__.'/api/admin.php';

// Distribution, ISRC, and platform webhook routes
require __DIR__.'/api/distribution.php';

// Podcast API Routes
require __DIR__.'/api/podcasts.php';

// Mobile API Routes (React Native app — downloads, sync, social, push notifications)
require __DIR__.'/api/mobile.php';
