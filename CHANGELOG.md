# Changelog

All notable changes to the TesoTunes API will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Notification System Expansion (L5)** — Full notification infrastructure
  - `ExpoPushChannel` — Laravel notification channel driver for Expo Push API
  - `NewFollowerNotification` — Push + DB notification when users follow artists
  - `SongUploadedNotification` — Notifies followers when artist uploads new music
  - `DownloadMilestoneNotification` — Notifies artist when their song is downloaded
  - `SubscriptionNotification` — Handles subscribed/renewed/cancelled/expired/payment_failed events
  - `SecurityAlertNotification` — New login, password change, new device, suspicious activity alerts (email + push)
  - `NewCommentNotification` — Push notification for comments and replies on content
  - `NewLikeNotification` — Push notification when content is liked
  - `StreamMilestoneNotification` — Celebrates stream milestones (100, 1K, 10K, 100K, 1M plays)
  - `CreditsEarnedNotification` — Notifies users when credits are awarded
  - `WelcomeNotification` — Welcome email + push for new user registrations
  - `PlaylistFeaturedNotification` — Notification when playlist gets featured
- Notification triggers wired into 10 controllers/services:
  - `ArtistController::toggleFollow()` → `NewFollowerNotification`
  - `AuthController::register()` → `WelcomeNotification`
  - `AuthController::login()` → `SecurityAlertNotification`
  - `SongService::uploadSong()` → `SongUploadedNotification` (to all followers)
  - `SongService::downloadSong()` → `DownloadMilestoneNotification`
  - `LikeObserver::created()` → `NewLikeNotification` (push)
  - `CommentController::store()` → `NewCommentNotification` (push for replies + top-level)
  - `PlayerController::recordPlay()` → `StreamMilestoneNotification`
  - `CreditService::awardCredits()` → `CreditsEarnedNotification`
  - `SubscriptionController::cancel()` → `SubscriptionNotification`
  - `SubscriptionController::subscribe()` → `SubscriptionNotification`
- Sentry error tracking integration (`sentry/sentry-laravel ^4.20`)
- Laravel Telescope for development debugging (`laravel/telescope ^5.17`)
- API request/response logging middleware (`ApiLoggingMiddleware`)
- `HasPagination` trait for standardized pagination across all endpoints
- `Cache-Control` and `X-API-Version` response headers
- Performance indexes on high-traffic tables (songs, users, plays, follows, etc.)
- SACCO module database tables (15 tables: savings, loans, shares, dividends, settings, audit, board)
- `artist_profiles` table
- Social routes (`routes/api/social.php`) — follows, comments, shares
- Engagement routes (`routes/api/engagement.php`) — polls, awards

### Changed
- Renamed `play_history` table to `play_histories`
- Renamed `playlist_song` table to `playlist_songs`
- Standardized pagination across all list endpoints (max 100, default 20)
- `EventTicket` model now maps to `event_tickets` table (was `event_ticket_types`)
- `EventAttendee` model now maps to `event_attendees` table (was `event_registrations`)

### Fixed
- `CrossModuleNotification` bug: `$this->following_type = $type` → `$this->type = $type` (property assignment was wrong)
- 4 Sacco notification classes now implement `ShouldQueue` for async processing:
  - `DepositConfirmedNotification`, `LoanApprovedNotification`, `MemberApprovedNotification`, `RepaymentDueNotification`
- Sanctum configuration published with correct stateful domains
- API rate limiting enabled (100/min authenticated, 20/min guest)
- `NotificationController::updateSettings()` now persists preferences to database
- `ArtistApiController::withdraw()` now processes withdrawals via `PayoutService`
- `TicketController` mobile money purchases integrated with ZengaPay payment gateway
- Removed duplicate `personal_access_tokens` migration

### Removed
- Stub route files: `ecommerce.php`, `loyalty.php`, `wazuh.php` (unused, no implementation)

### Security
- Verified all 38 `DB::raw()` calls are safe (static aggregations only, no user input)
- Verified `SecurityHeadersMiddleware` covers all recommended headers (CSP, HSTS, X-Frame, etc.)
- Verified mass assignment protection on all models (Featurable trait false alarm resolved)
- CORS correctly restricts origins to known domains

## [1.0.0] - 2026-02-23

### Added
- Initial TesoTunes API release
- Music streaming and download endpoints
- Artist management and analytics
- User authentication via Laravel Sanctum
- Credits system (earn/spend platform currency)
- Subscription tiers (Free, Premium, Artist, Label)
- Mobile money payments via ZengaPay (MTN MoMo, Airtel Money)
- Podcast hosting and streaming
- Events and ticketing
- Social features (follows, likes, comments, shares)
- Feed system with preferences and analytics
- Awards and polls
- SACCO (Savings and Credit Cooperative) module
- Store/E-commerce module
- Push notifications via Firebase
- Admin and moderation tools
