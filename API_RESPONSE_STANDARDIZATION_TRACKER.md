# API Response Standardization Tracker

**Goal:** Standardize ALL TesoTunes Laravel API responses to use Laravel's built-in `JsonResource` / `ResourceCollection` pattern — eliminating manual `{ success, data }` wrapping, fixing broken routes, and unifying pagination metadata.

**Started:** 2026-02-12
**Status:** IN PROGRESS
**Payment Provider:** ZengaPay only (all alternatives removed)

---

## Code Citations

- **License: MIT** — https://github.com/tupical/MebelOrg-Blog/blob/498afb790cad1edb0639de5cf8ab644f54f8e8c9/app/Http/Resources/Category.php
- **License: MIT** — https://github.com/karlomikus/bar-assistant/blob/ffef99d96934aa853e27397ca0ca9863fa05ecf2/app/Models/Cocktail.php
- **License: MIT** — https://github.com/jard12/gocd2/blob/a18bd1a98a340b84d12742734e3336b4d9bca2df/app/Models/Cocktail.php

---

## Current State Audit

### Existing JsonResource classes (only 2)
- `app/Http/Resources/PodcastResource.php`
- `app/Http/Resources/PodcastEpisodeResource.php`

### Response Patterns Found Across All Modules

| Pattern | Used By | Problem |
|---------|---------|---------|
| `{ success: true, data: ..., meta: ... }` | Admin controllers, Store module (11 controllers) | Non-standard; `success` is redundant |
| `{ notifications: [...], pagination: {...} }` | NotificationController | Completely custom keys |
| Raw paginator / bare model `response()->json($model)` | EpisodeApiController | No consistent envelope |
| `{ data: [...], meta: {...} }` (no `success`) | PodcastApiController | Close but manually wrapped, no `links` |
| `{ success: true, data: null, message: "Not implemented yet." }` | **6 stub controllers**: V2/Feed, all 6 Sacco controllers | Need real implementation + standard format |
| Inline closures with `{ success: true, data: {...} }` | Store cart/product routes in `routes/api/store.php` | Logic in route files, not controllers |

---

## Problems to Fix

| # | Problem | Detail |
|---|---------|--------|
| 1 | Manual `{ success, data }` wrapping | ~30 controllers manually wrap instead of using `JsonResource` / `ResourceCollection` |
| 2 | Broken/wrong content-type routes | `GET /genres/{slug}` → HTML, `GET /albums/{id}` → 404, `GET /playlists/featured` → 500, `GET /playlists/{id}` → 500 |
| 3 | Inconsistent pagination metadata | Some use `pagination`, some use `meta`, none follow Laravel's standard `{ data, links, meta }` |
| 4 | 8 stub controllers | PollVote, ActivityInteraction, FeedV2, all 6 Sacco controllers return "Not implemented yet." |
| 5 | Business logic in route closures | Store cart endpoints defined as closures in `routes/api/store.php` instead of controllers |
| 6 | No JsonResource classes | Only 2 exist (Podcast). Need: Genre, Song, Artist, Album, Playlist, Forum, Event, Poll, Sacco, Store, Notification, Feed, Campaign, Award |

## Target Response Formats

**Single resource:**
```json
{ "data": { "id": 4, "name": "Afrobeat", "slug": "afrobeat", "description": "...", "song_count": 42 } }
```

**Paginated collection:**
```json
{
  "data": [ ... ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 55, "from": 1, "to": 20 },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

**Non-paginated collection:**
```json
{ "data": [ ... ] }
```

**Error:**
```json
{ "message": "Resource not found", "errors": {} }
```

## Done Criteria (per endpoint)

1. Returns proper JSON (not HTML)
2. Uses `JsonResource` / `ResourceCollection` (no manual `{ success, data }` wrapping)
3. Uses `meta` for pagination (not `pagination`)
4. Has proper error responses (404 JSON, not HTML)
5. Is accessible via `/api/` route prefix

---

## Tracking Checklist

### Genres
- [x] Create `GenreResource`
- [x] `GET /api/genres` — list all genres (collection via `GenreResource`)
- [x] `GET /api/genres/{slug}` — single genre by slug (**fixed — returns JSON now**)
- [x] `GET /api/genres/{id}` — single genre by ID (**fixed — returns JSON now**)
- [x] `GET /api/genres/{id}/songs` — songs in genre (paginated)
- [x] `GET /api/genres/{id}/artists` — artists in genre (paginated)
- [x] `GET /api/genres/{id}/albums` — albums in genre (paginated)

### Playlists
- [x] Create `PlaylistResource`
- [x] `GET /api/playlists` — list playlists (paginated)
- [x] `GET /api/playlists/featured` — featured playlists (**fixed — was 500**)
- [x] `GET /api/playlists/{slug}` — single playlist
- [x] `GET /api/playlists/{id}` — single playlist (**fixed — was 500**)
- [x] `GET /api/playlists/{id}/tracks` — tracks in playlist
- [x] `POST /api/playlists` — create playlist
- [x] `PUT /api/playlists/{id}` — update playlist
- [x] `POST /api/playlists/{id}/songs` — add song to playlist
- [x] `DELETE /api/playlists/{id}/songs/{songId}` — remove song

### Albums
- [x] Create `AlbumResource`
- [x] `GET /api/albums` — list albums (paginated)
- [x] `GET /api/albums/{slug}` — single album by slug
- [x] `GET /api/albums/{id}` — single album by ID (**fixed — now resolves by id, slug, or uuid**)
- [x] `GET /api/albums/{id}/tracks` — album tracks

### Songs
- [x] Create `SongResource`
- [x] `GET /api/songs` — list songs (paginated)
- [x] `GET /api/songs/{slug}` — single song
- [x] `GET /api/songs/{id}` — single song

### Artists
- [x] Create `ArtistResource`
- [x] `GET /api/artists` — list artists (paginated)
- [x] `GET /api/artists/{slug}` — single artist
- [x] `GET /api/artists/{id}` — single artist
- [x] `GET /api/artists/{id}/songs` — artist songs (paginated)
- [x] `GET /api/artists/{id}/albums` — artist albums (paginated)

### Auth & User
- [x] `POST /api/auth/login` — login (uses `UserResource`)
- [x] `POST /api/auth/register` — register (uses `UserResource`)
- [x] `GET /api/user` — current user profile (uses `UserResource`)
- [x] `PUT /api/user` — update profile (uses `UserResource`, route registered)
- [x] `GET /api/user/library` — user library (aggregated: liked songs, playlists, plays, downloads, followed artists)
- [x] `POST /api/like/{type}/{id}` — toggle like (polymorphic via `Like::toggle()`)
- [x] `POST /api/bookmark/{type}/{id}` — toggle bookmark (uses `likes` table with `type=bookmark`)

### Forum
> **Controller:** `ForumsApiController` — standardized with `ForumThreadResource`, `ForumReplyResource`
- [x] Create `ForumThreadResource`, `ForumReplyResource`
- [x] `GET /api/admin/forums` — list threads (paginated)
- [x] `GET /api/admin/forums/stats` — forum stats
- [x] `GET /api/admin/forums/categories` — forum categories (route ordering fix)
- [x] `GET /api/admin/forums/{id}` — single thread detail
- [x] `GET /api/admin/forums/{id}/replies` — thread replies (paginated)
- [x] `DELETE /api/admin/forums/{id}` — delete thread
- [x] `POST /api/admin/forums/{id}/pin` — toggle pin
- [x] `POST /api/admin/forums/{id}/lock` — toggle lock

### Polls
> **Controller:** `PollVoteController` — standardized with `PollResource`, `PollOptionResource`
> **Models:** `Poll`, `PollOption`, `PollVote` created at `app/Models/Modules/Forum/`
- [x] Create `PollResource`, `PollOptionResource`
- [x] `POST /api/polls/{poll}/vote` — cast vote (validates active/voted, supports multi-vote)
- [x] `GET /api/polls/{poll}/results` — get results (visibility controlled by PollResource)

### Events
> **Controller:** `EventsApiController` — standardized with `EventResource`
- [x] Create `EventResource`
- [x] `GET /api/admin/events` — list events (paginated)
- [x] `GET /api/admin/events/stats` — event stats
- [x] `GET /api/admin/events/{id}` — single event
- [x] `POST /api/admin/events` — create event
- [x] `PUT /api/admin/events/{id}` — update event
- [x] `DELETE /api/admin/events/{id}` — delete event
- [x] `GET /api/admin/events/{id}/registrations` — event registrations
- [x] `POST /api/events/{id}/interest` — toggle interest (uses `interestedEvents()->toggle()`)

### Awards (Nominations & Voting)
> **Controller:** `AwardsApiController` — standardized with `AwardResource`, `AwardCategoryResource`, `AwardNominationResource`
> **Models:** `Award` model created. `AwardNomination` updated with `votes()` relationship. Tables already exist in DB.
- [x] Create `Award` model (**created** — scopes, relationships, status helpers)
- [x] Create migrations for `awards`, `award_categories`, `award_nominations`, `award_votes` tables (**tables already exist**)
- [x] Create `AwardResource`, `AwardCategoryResource`, `AwardNominationResource`
- [x] Create `AwardsApiController`
- [x] `GET /api/awards` — list awards / seasons
- [x] `GET /api/awards/current-season` — current award season
- [x] `GET /api/awards/{id}` — single award detail
- [x] `GET /api/awards/{id}/categories` — award categories
- [x] `GET /api/awards/{id}/categories/{categoryId}/nominations` — nominations for category
- [x] `POST /api/awards/{id}/nominations` — submit nomination
- [x] `POST /api/awards/{id}/vote` — cast vote
- [x] `GET /api/awards/{id}/results` — voting results

### Ojokotau (Crowdfunding / Campaigns)
> **Controller:** `CampaignsApiController` — standardized with `CampaignResource`, `PledgeResource`, `CampaignUpdateResource`
> **Models:** `Campaign`, `CampaignPledge`, `CampaignUpdate` created. All raw `DB::table()` replaced with Eloquent.
- [x] Create `Campaign`, `CampaignPledge`, `CampaignUpdate` models
- [x] Create `CampaignResource`, `PledgeResource`, `CampaignUpdateResource`
- [x] `GET /api/admin/campaigns` — list campaigns (paginated)
- [x] `GET /api/admin/campaigns/stats` — campaign stats
- [x] `GET /api/admin/campaigns/{id}` — single campaign
- [x] `POST /api/admin/campaigns` — create campaign
- [x] `PUT /api/admin/campaigns/{id}` — update campaign
- [x] `DELETE /api/admin/campaigns/{id}` — delete campaign
- [x] `POST /api/admin/campaigns/{id}/approve` — approve campaign
- [x] `POST /api/admin/campaigns/{id}/reject` — reject campaign
- [x] `GET /api/admin/campaigns/{id}/pledges` — campaign pledges (paginated)
- [x] `GET /api/admin/campaigns/{id}/updates` — campaign updates (paginated)

### SACCO
> **Controllers:** 6 Sacco controllers + `SaccoApiController` (admin)
> **All 6 SACCO controllers fully implemented** with proper response standardization.
- [x] Create `SaccoMemberResource`, `SaccoLoanResource`, `SaccoTransactionResource`, `SaccoShareResource` + `SaccoSavingsAccountResource`

#### SACCO — Membership (`SaccoMembershipController` — **implemented**)
- [x] `GET /api/sacco/members` — list members
- [x] `POST /api/sacco/members` — register member
- [x] `GET /api/sacco/members/{member}` — member detail
- [x] `PUT /api/sacco/members/{member}` — update member
- [x] `PATCH /api/sacco/members/{member}/status` — update status

#### SACCO — Savings (`SaccoSavingsController` — **implemented**)
- [x] `POST /api/sacco/savings/accounts` — open account
- [x] `POST /api/sacco/savings/deposit` — deposit
- [x] `POST /api/sacco/savings/withdraw` — withdraw
- [x] `GET /api/sacco/savings/accounts/{account}` — account detail
- [x] `GET /api/sacco/savings/transactions/{account}` — transactions
- [x] `GET /api/sacco/savings/balance/{account}` — balance

#### SACCO — Loans (`SaccoLoanController` — **implemented**)
- [x] `POST /api/sacco/loans/apply` — apply for loan
- [x] `POST /api/sacco/loans/{loan}/approve` — approve loan
- [x] `POST /api/sacco/loans/{loan}/disburse` — disburse loan
- [x] `POST /api/sacco/loans/{loan}/repay` — repay loan
- [x] `GET /api/sacco/loans/{loan}` — loan detail
- [x] `GET /api/sacco/loans/member/{member}` — member loans
- [x] `GET /api/sacco/loans/{loan}/schedule` — repayment schedule
- [x] `GET /api/sacco/loans/{loan}/balance` — loan balance

#### SACCO — Shares (`SaccoSharesController` — **implemented**)
- [x] `POST /api/sacco/shares/purchase` — purchase shares
- [x] `POST /api/sacco/shares/transfer` — transfer shares
- [x] `GET /api/sacco/shares/member/{member}` — member shares
- [x] `GET /api/sacco/shares/value` — current share value

#### SACCO — Reports (`SaccoReportsController` — **implemented**)
- [x] `GET /api/sacco/reports/membership` — membership report
- [x] `GET /api/sacco/reports/loans` — loans report
- [x] `GET /api/sacco/reports/savings` — savings report
- [x] `GET /api/sacco/reports/shares` — shares report
- [x] `GET /api/sacco/reports/financial` — financial report
- [x] `GET /api/sacco/reports/member/{member}` — member statement
- [x] `GET /api/sacco/reports/overdue` — overdue report

#### SACCO — Analytics (`SaccoAnalyticsController` — **implemented**)
- [x] `GET /api/sacco/analytics/dashboard` — analytics dashboard
- [x] `GET /api/sacco/analytics/trends/membership` — membership trends
- [x] `GET /api/sacco/analytics/performance/loans` — loan performance
- [x] `GET /api/sacco/analytics/savings` — savings analytics
- [x] `GET /api/sacco/analytics/repayments` — repayment analytics
- [x] `GET /api/sacco/analytics/portfolio` — portfolio
- [x] `GET /api/sacco/analytics/activity` — activity
- [x] `GET /api/sacco/analytics/top-performers` — top performers
- [x] `GET /api/sacco/analytics/risk` — risk analytics

#### SACCO — Admin (`SaccoApiController` — standardized, removed `{ success }` wrapping and try/catch)
- [x] `GET /api/admin/sacco/stats` — sacco stats
- [x] `GET /api/admin/sacco/members` — member management
- [x] `GET /api/admin/sacco/loans` — loan management
- [x] `GET /api/admin/sacco/loans/{id}` — loan detail
- [x] `POST /api/admin/sacco/loans/{id}/approve` — approve loan
- [x] `POST /api/admin/sacco/loans/{id}/reject` — reject loan
- [x] `POST /api/admin/sacco/loans/{id}/disburse` — disburse loan
- [x] `GET /api/admin/sacco/loans/{id}/repayments` — loan repayments
- [x] `GET /api/admin/sacco/transactions` — savings transactions

### Store
> **Controllers:** 11 Store module controllers — ALL standardized (removed `{ success }` wrapping, try/catch, Validator::make)
> **StoreController** — 6 methods (index, show, store, update, statistics, featured)
> **PaymentController** — 4 methods (initiate, status, webhook, methods)
> **NotificationController** — 6 methods (index, markAsRead, markAllAsRead, destroy, getPreferences, updatePreferences)
> **AnalyticsController** — 3 methods (dashboard, realtime, export)
> **ReportController** — 3 methods (index, generate, download)
> **ReviewController** — 7 methods (productReviews, createProductReview, update, destroy, markHelpful, addSellerResponse, canReview)
- [x] Create `ProductResource`, `StoreResource`, `OrderResource`, `CartResource`, `CartItemResource`, `OrderItemResource`, `ReviewResource`

#### Store — Products (`ProductController` — standardized, removed `{ success }` wrapping)
- [x] `GET /api/store/products/search` — search products
- [x] `POST /api/store/products/check-availability` — check availability

#### Store — Cart (`CartController` — standardized, removed `{ success }` wrapping and try/catch)
- [x] `GET /api/store/cart` — get cart
- [x] `POST /api/store/cart/add` — add to cart
- [x] `PUT /api/store/cart/update` — update cart item
- [x] `DELETE /api/store/cart/remove` — remove item
- [x] `DELETE /api/store/cart/clear` — clear cart
- [x] `PUT /api/store/cart/items/{itemId}` — update item (REST)
- [x] `DELETE /api/store/cart/items/{itemId}` — delete item (REST)

#### Store — Orders (`OrderController` — standardized, removed `{ success }` wrapping and try/catch)
- [x] `GET /api/store/orders` — list orders (paginated)
- [x] `POST /api/store/orders` — create order
- [x] `GET /api/store/orders/{orderNumber}` — order detail
- [x] `POST /api/store/orders/{orderNumber}/cancel` — cancel order

#### Store — Promotions (`PromotionController` — standardized, removed `{ success }` wrapping)
- [x] `GET /api/store/promotions` — list promotions (paginated)
- [x] `GET /api/store/promotions/my-promotions` — my promotions
- [x] `GET /api/store/promotions/{slug}` — promotion detail
- [x] `POST /api/store/promotions/order-items/{orderItem}/submit-verification` — submit verification
- [x] `POST /api/store/promotions/order-items/{orderItem}/dispute` — dispute

#### Store — Seller Promotions (`SellerPromotionController` — standardized, removed `{ success }` wrapping)
- [x] `GET /api/store/seller/promotions` — list seller promotions
- [x] `POST /api/store/seller/promotions` — create promotion
- [x] `PUT /api/store/seller/promotions/{product}` — update promotion
- [x] `DELETE /api/store/seller/promotions/{product}` — delete promotion
- [x] `GET /api/store/seller/promotions/pending-verifications` — pending verifications
- [x] `POST /api/store/seller/promotions/order-items/{orderItem}/verify` — verify completion
- [x] `GET /api/store/seller/promotions/statistics` — seller statistics

#### Store — Admin (`StoreApiController` — standardized, removed `{ success }` wrapping and try/catch)
- [x] `GET /api/admin/store/stats` — store stats
- [x] `GET /api/admin/store/products` — product management (paginated)
- [x] `POST /api/admin/store/products` — create product (**implemented**)
- [x] `PUT /api/admin/store/products/{product}` — update product (**implemented**)
- [x] `POST /api/admin/store/products/{product}/toggle-status` — toggle status
- [x] `DELETE /api/admin/store/products/{product}` — delete product
- [x] `GET /api/admin/store/orders` — order management (paginated)
- [x] `POST /api/admin/store/orders/{order}/status` — update order status (**implemented**)
- [x] `GET /api/admin/store/shops` — shop management (paginated)
- [x] `POST /api/admin/store/shops` — create shop (**implemented**)
- [x] `PUT /api/admin/store/shops/{store}` — update shop (**implemented**)
- [x] `POST /api/admin/store/shops/{store}/toggle-status` — toggle shop status
- [x] `POST /api/admin/store/shops/{store}/approve` — approve shop
- [x] `POST /api/admin/store/shops/{store}/suspend` — suspend shop
- [x] `POST /api/admin/store/shops/{store}/verify` — verify shop
- [x] `POST /api/admin/store/shops/{store}/unverify` — unverify shop
- [x] `DELETE /api/admin/store/shops/{store}` — delete shop
- [x] `GET /api/admin/store/analytics` — store analytics

### Podcasts
> **Controllers:** `PodcastApiController`, `EpisodeApiController`, `PlayerApiController` — all standardized with `PodcastResource` / `PodcastEpisodeResource`
> **Changes:** Removed manual `{ data, meta }` wrapping; let `ResourceCollection` handle envelope. Added 7 missing methods to PodcastApiController. Replaced all PlayerApiController stubs with real implementations using `podcast_listens` table.
- [x] Fix `PodcastApiController` — stop manual wrapping, let `ResourceCollection` handle envelope
- [x] Fix `EpisodeApiController` — use `PodcastEpisodeResource` consistently
- [x] `GET /api/podcasts` — list podcasts (paginated)
- [x] `GET /api/podcasts/{uuid}` — single podcast
- [x] `GET /api/podcasts/{uuid}/episodes` — podcast episodes (paginated)
- [x] `GET /api/podcasts/{uuid}/rss` — RSS feed
- [x] `POST /api/podcasts/{uuid}/subscribe` — subscribe
- [x] `DELETE /api/podcasts/{uuid}/unsubscribe` — unsubscribe
- [x] `GET /api/episodes/{uuid}` — single episode
- [x] `GET /api/podcasts-search` — search podcasts
- [x] `GET /api/podcasts-trending` — trending podcasts
- [x] `GET /api/podcast-categories` — podcast categories
- [x] `POST /api/episodes/{uuid}/play` — record play (**implemented**)
- [x] `POST /api/episodes/{uuid}/progress` — update progress (**implemented**)
- [x] `POST /api/episodes/{uuid}/complete` — mark complete (**implemented**)
- [x] `GET /api/my-podcast-subscriptions` — my subscriptions
- [x] `GET /api/my-listening-queue` — listening queue (**implemented**)
- [x] `GET /api/my-recent-podcasts` — recently played (**implemented**)

### Notifications
> **Controller:** `NotificationController` — standardized with `NotificationResource`
> **Changes:** Replaced custom `{ notifications, pagination, unread_counts }` format with `NotificationResource::collection()` for paginated endpoints, `{ data: {...} }` for non-collection responses, `firstOrFail()` for 404s, `204` for deletes.
- [x] Create `NotificationResource`
- [x] `GET /api/notifications` — list notifications (paginated)
- [x] `GET /api/notifications/unread-counts` — unread counts
- [x] `GET /api/notifications/recent` — recent notifications
- [x] `GET /api/notifications/settings` — notification settings
- [x] `PUT /api/notifications/settings` — update settings
- [x] `POST /api/notifications/mark-all-read` — mark all read
- [x] `POST /api/notifications/{notification}/mark-read` — mark single read
- [x] `DELETE /api/notifications/{notification}` — delete notification
- [x] `GET /api/notifications/analytics` — notification analytics (admin)

### Feed (Edula Discovery)
> **Controller:** `FeedController` — fully implemented using `FeedService`, `FeedPreferenceService`, `FeedAnalyticsService`. Models: `FeedItem`, `FeedPreference`, `FeedABTest`, `FeedAnalytic`, `UserFeedSetting`. DTO: `DTOs\Feed\FeedItem`.
- [x] Create `FeedItem` model, `FeedItemDTO`, supporting models
- [x] `GET /api/feed` — main feed
- [x] `GET /api/feed/for-you` — personalized feed
- [x] `GET /api/feed/discover` — discovery feed
- [x] `GET /api/feed/module/{module}` — module-specific feed
- [x] `GET /api/feed/tabs` — feed tabs
- [x] `GET /api/feed/following` — following feed
- [x] `GET /api/feed/saved` — saved items
- [x] `GET /api/feed/{uuid}` — single feed item
- [x] `POST /api/feed/{uuid}/not-interested` — not interested
- [x] `POST /api/feed/{uuid}/hide` — hide item
- [x] `POST /api/feed/{uuid}/save` — save item
- [x] `DELETE /api/feed/{uuid}/save` — unsave item
- [x] `POST /api/feed/{uuid}/click` — track click
- [x] `POST /api/feed/{uuid}/engage` — track engagement
- [x] `POST /api/feed/refresh` — refresh feed
- [x] `GET /api/feed/preferences` — feed preferences
- [x] `PUT /api/feed/preferences` — update preferences

### Admin — General
> **Controllers:** `DashboardController`, `SettingsController`, `AdminUsersController`, `AdminArtistsController`, `PaymentController` — all standardized (removed `{ success }` wrapping, proper `{ data }` / `{ message }` envelopes, 204 for deletes)
- [x] `GET /api/admin/dashboard/stats` — dashboard stats
- [x] `GET /api/admin/dashboard/recent-activity` — recent activity
- [x] `GET /api/admin/settings` — settings
- [x] `PUT /api/admin/settings` — update settings
- [x] `GET /api/admin/users` — user management (paginated)
- [x] `GET /api/admin/users/statistics` — user statistics
- [x] `GET /api/admin/users/{id}` — single user
- [x] `GET /api/admin/artists` — artist management (paginated)
- [x] `GET /api/admin/artists/statistics` — artist statistics
- [x] `GET /api/admin/artists/{id}` — single artist detail
- [x] `POST /api/admin/artists/{id}` — update artist
- [x] `DELETE /api/admin/artists/{id}` — delete artist
- [x] `POST /api/admin/artists/{id}/verify` — verify artist
- [x] `POST /api/admin/artists/{id}/toggle-verify` — toggle verify
- [x] `POST /api/admin/artists/{id}/toggle-featured` — toggle featured
- [x] `POST /api/admin/artists/{id}/status` — update status
- [x] `GET /api/admin/payment-analytics` — payment analytics (**implemented** — wired to `PaymentService::getPaymentAnalytics()`)

### Admin — Roles & Permissions
> **Controller:** `RoleController` — standardized (removed `{ success }` wrapping, manual `Validator` → `$request->validate()`, removed broad try/catch, proper `{ data }` / `{ message }` envelopes, 204 for deletes)
- [x] `GET /api/admin/roles` — list roles
- [x] `GET /api/admin/roles/{role}` — role detail
- [x] `POST /api/admin/roles` — create role
- [x] `PUT /api/admin/roles/{role}` — update role
- [x] `DELETE /api/admin/roles/{role}` — delete role
- [x] `POST /api/admin/roles/assign` — assign role to user
- [x] `POST /api/admin/roles/remove` — remove role from user
- [x] `GET /api/admin/permissions` — list permissions

### Search & Misc
> **Controllers:** `DiscoverController` — standardized (removed `{ success }` wrapping, wrapped in `{ data }` envelope)
> `PlayerController` — standardized (removed `{ success }` wrapping, try/catch, manual validation handling)
> `Player/PlayerController` — standardized (removed `{ success }` wrapping, Validator::make → $request->validate(), removed try/catch)
> `SlideshowController` — standardized (removed `{ success }` wrapping)
- [x] `GET /api/search?q=` — search across entities
- [x] `GET /api/trending` — trending songs
- [x] `POST /api/player/record-play` — record a play
- [x] `POST /api/player/update-now-playing` — update now playing
- [x] `GET /api/slideshow/{section}` — slideshow content
- [x] `GET /api/slideshow/genre/{slug}` — slideshow by genre
- [x] `GET /api/slideshow/mood/{slug}` — slideshow by mood

### Mobile Content
> **Controller:** `MobileContentController` — standardized (removed `{ success }` wrapping)
- [x] `GET /api/mobile/trending/songs` — trending songs
- [x] `GET /api/mobile/popular/artists` — popular artists
- [x] `GET /api/mobile/popular/albums` — popular albums
- [x] `GET /api/mobile/radio/stations` — radio stations
- [x] `GET /api/mobile/featured/charts` — featured charts

### Activity & Interactions
> **Controller:** `ActivityInteractionController` — standardized, `ActivityController` — stub standardized (501 status)
- [x] `POST /api/like/{type}/{id}` — toggle like (implemented in ActivityInteractionController)
- [x] `POST /api/bookmark/{type}/{id}` — toggle bookmark (implemented in ActivityInteractionController)
- [x] `POST /api/activities/{activity}/like` — like activity (**implemented**)
- [x] `DELETE /api/activities/{activity}/like` — unlike activity (**implemented**)
- [x] `GET /api/activities/{activity}/comments` — get comments (**implemented**, paginated)
- [x] `POST /api/activities/{activity}/comments` — add comment (**implemented**)

### Payments & Subscriptions
> **Controllers:** `PaymentController` — standardized (removed `__call` stub, implemented `processSubscription`, `refund`, `artistPayout`, `webhook`)
> `PayoutController` — standardized (removed `{ success }` wrapping, implemented `requestPayout` with balance check)
> `SubscriptionController` — standardized (removed `__call` stub, implemented `cancel` and `extend` via `PaymentService`)
- [x] `POST /api/payments/subscription` — process subscription (**implemented** via PaymentService)
- [x] `POST /api/payments/{payment}/refund` — refund (**implemented** via PaymentService)
- [x] `POST /api/payments/artist-payout` — artist payout (**implemented** via PaymentService)
- [x] `POST /api/payouts/request` — request payout (**implemented** with balance validation)
- [x] `POST /api/subscriptions/{subscription}/cancel` — cancel subscription (**implemented** via PaymentService)
- [x] `POST /api/subscriptions/{subscription}/extend` — extend subscription (**implemented** via PaymentService)

---

## Implementation Pattern

```php
// app/Http/Resources/GenreResource.php
class GenreResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'song_count' => $this->songs_count,
        ];
    }
}
```

**Controller usage (single):**
```php
return new GenreResource($genre);
```

**Controller usage (paginated collection):**
```php
return GenreResource::collection(Genre::paginate(20));
```

**Controller usage (non-paginated collection):**
```php
return GenreResource::collection($featuredPlaylists);
```

---

## Progress Summary

| Section | Total | Done | Remaining |
|---------|-------|------|-----------|
| Genres | 7 | 7 | 0 |
| Playlists | 10 | 10 | 0 |
| Albums | 5 | 5 | 0 |
| Songs | 4 | 4 | 0 |
| Artists | 6 | 6 | 0 |
| Auth & User | 7 | 7 | 0 |
| Forum | 9 | 9 | 0 |
| Polls | 3 | 3 | 0 |
| Events | 9 | 9 | 0 |
| Awards (Nominations) | 12 | 12 | 0 |
| Ojokotau (Campaigns) | 12 | 12 | 0 |
| SACCO — Membership | 5 | 5 | 0 |
| SACCO — Savings | 6 | 6 | 0 |
| SACCO — Loans | 8 | 8 | 0 |
| SACCO — Shares | 4 | 4 | 0 |
| SACCO — Reports | 7 | 7 | 0 |
| SACCO — Analytics | 9 | 9 | 0 |
| SACCO — Admin | 9 | 9 | 0 |
| Store — Products | 2 | 2 | 0 |
| Store — Cart | 7 | 7 | 0 |
| Store — Orders | 4 | 4 | 0 |
| Store — Promotions | 5 | 5 | 0 |
| Store — Seller Promotions | 7 | 7 | 0 |
| Store — Admin | 18 | 18 | 0 |
| Store — Core (Store/Payment/Notification/Analytics/Report/Review) | 29 | 29 | 0 |
| Podcasts | 18 | 18 | 0 |
| Notifications | 10 | 10 | 0 |
| Feed (Edula) | 18 | 18 | 0 |
| Admin — General | 17 | 17 | 0 |
| Admin — Roles & Permissions | 8 | 8 | 0 |
| Search & Misc | 7 | 7 | 0 |
| Mobile Content | 5 | 5 | 0 |
| Activity & Interactions | 6 | 6 | 0 |
| Payments & Subscriptions | 6 | 6 | 0 |
| Player (queue-based) | 5 | 5 | 0 |
| **TOTAL** | **~314** | **~314** | **~0** |
