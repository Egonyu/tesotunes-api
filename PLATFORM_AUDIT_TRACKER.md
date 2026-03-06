# TesoTunes Platform - Audit Issue Tracker

**Audit Date:** February 23, 2026  
**Overall Health Score:** 78/100  
**Risk Level:** MEDIUM  
**Last Updated:** February 23, 2026

---

## How to Use This Tracker

- `[ ]` — Not started
- `[~]` — In progress
- `[x]` — Completed
- Add the date completed next to each item when done

---

## 🚨 CRITICAL Issues (Must Fix Before Production)

### C1. Missing Sanctum Configuration
- **Impact:** API authentication will fail
- **Effort:** 5 minutes
- **Status:** [x] Completed
- **Date Fixed:** Feb 23, 2026
- **Steps:**
  1. [x] Run `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`
  2. [x] Configure `config/sanctum.php` with stateful domains (tesotunes.test, tesotunes.com, www.tesotunes.com, api.tesotunes.com)
  3. [x] Add frontend URL to `SANCTUM_STATEFUL_DOMAINS` in `.env` (defaults configured)
  4. [ ] Verify authentication works

### C2. Missing Database Migrations (47 Models)
- **Impact:** Database schema incomplete, features won't work
- **Effort:** 8-16 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___

#### Priority 1 — SACCO Module (15 models)
- [ ] `sacco_members` migration
- [ ] `sacco_loans` migration
- [ ] `sacco_savings_accounts` migration
- [ ] `sacco_contributions` migration
- [ ] `sacco_loan_repayments` migration
- [ ] `sacco_groups` migration
- [ ] `sacco_meetings` migration
- [ ] `sacco_fines` migration
- [ ] `sacco_dividends` migration
- [ ] `sacco_guarantors` migration
- [ ] `sacco_shares` migration
- [ ] `sacco_withdrawal_requests` migration
- [ ] `sacco_settings` migration
- [ ] `sacco_transactions` migration
- [ ] `sacco_notifications` migration

#### Priority 2 — Feed System (4 models)
- [ ] `feed_items` migration
- [ ] `feed_preferences` migration
- [ ] `feed_analytics` migration
- [ ] `feed_ab_tests` migration

#### Priority 3 — Podcasts (3 models)
- [ ] `podcasts` migration
- [ ] `podcast_episodes` migration
- [ ] `podcast_categories` migration

#### Priority 4 — Campaigns (3 models)
- [ ] `campaigns` migration
- [ ] `campaign_pledges` migration
- [ ] `campaign_updates` migration

#### Priority 5 — Supporting Features
- [ ] `activities` migration
- [ ] `comments` migration
- [ ] `posts` migration
- [ ] `isrc_codes` migration
- [ ] `publishing_rights` migration
- [ ] `artist_profiles` migration
- [ ] `moods` migration
- [ ] `device_tokens` migration
- [ ] `settings` migration
- [ ] `audit_logs` migration
- [ ] Other remaining models (list as identified)

### C3. No API Tests
- **Impact:** No safety net for code changes
- **Effort:** 5-10 dev days
- **Status:** [ ] Not started
- **Date Fixed:** ___

#### Authentication Tests
- [ ] `tests/Feature/Api/Auth/LoginTest.php`
- [ ] `tests/Feature/Api/Auth/RegisterTest.php`
- [ ] `tests/Feature/Api/Auth/LogoutTest.php`
- [ ] `tests/Feature/Api/Auth/PasswordResetTest.php`

#### Music API Tests
- [ ] `tests/Feature/Api/Music/SongCrudTest.php`
- [ ] `tests/Feature/Api/Music/AlbumTest.php`
- [ ] `tests/Feature/Api/Music/PlaylistTest.php`
- [ ] `tests/Feature/Api/Music/GenreTest.php`

#### Payment Tests
- [ ] `tests/Feature/Api/Payment/PaymentProcessingTest.php`
- [ ] `tests/Feature/Api/Payment/WebhookTest.php`
- [ ] `tests/Feature/Api/Payment/CreditsTest.php`

#### Social Feature Tests
- [ ] `tests/Feature/Api/Social/FollowTest.php`
- [ ] `tests/Feature/Api/Social/LikeTest.php`
- [ ] `tests/Feature/Api/Social/CommentTest.php`

#### SACCO Tests
- [ ] `tests/Feature/Api/Sacco/MembershipTest.php`
- [ ] `tests/Feature/Api/Sacco/LoanTest.php`
- [ ] `tests/Feature/Api/Sacco/SavingsTest.php`

### C4. Broken API Routes (6 Empty/Stub Files)
- **Impact:** 404 errors, incomplete modules
- **Effort:** Implement 2-3 days each, or remove 5 minutes each
- **Status:** [x] Completed
- **Date Fixed:** Feb 23, 2026
- [x] `routes/api/social.php` — **Populated** with artist follow, comments, shares, activity interaction routes
- [x] `routes/api/ecommerce.php` — **Removed** (redundant, store.php + Store module handles this)
- [x] `routes/api/engagement.php` — **Populated** with polls and awards routes (moved from inline api.php)
- [x] `routes/api/loyalty.php` — **Removed** (no implementation exists)
- [x] `routes/api/wazuh.php` — **Removed** (no implementation, config retained for future)
- [x] `routes/auth.php` — Already documented, web-based auth routes for NextAuth compatibility

---

## ⚠️ HIGH Priority Issues

### H1. No Rate Limiting on API Routes
- **Impact:** API abuse, DDoS vulnerability
- **Effort:** 15 minutes
- **Status:** [x] Completed
- **Date Fixed:** Feb 23, 2026
- [x] ~~Add `throttle:api` middleware~~ — Enabled via `$middleware->throttleApi('api')` in bootstrap/app.php
- [x] Rate limits already configured in `AppServiceProvider::configureRateLimiting()` (100/min auth, 20/min guest)
- [ ] Test throttle behavior

### H2. Permissive CORS Configuration
- **Impact:** ~~Security vulnerability — allows all origins (`*`)~~ **FALSE ALARM**
- **Effort:** N/A
- **Status:** [x] Already configured correctly
- **Date Fixed:** N/A (was never broken)
- **Notes:** CORS config already restricts origins to localhost, tesotunes.test, tesotunes.com, and subdomains via pattern. `supports_credentials: true` is set.

### H3. Raw SQL Queries (17 Found)
- **Impact:** ~~Potential SQL injection~~ **Low risk — reviewed and safe**
- **Effort:** N/A
- **Status:** [x] Reviewed
- **Date Fixed:** Feb 23, 2026
- [x] Found 38 `DB::raw` instances (not 17 as originally estimated)
- [x] All are safe static aggregation functions: COUNT(), SUM(), DATE(), DATE_FORMAT(), COALESCE()
- [x] No user input interpolation detected — no SQL injection risk
- **Remaining:** StoreApiController correlated subqueries could be refactored to Eloquent relationships for cleanliness (non-security)

### H4. Mass Assignment Protection Missing on Featurable
- **Impact:** ~~Potential mass assignment vulnerability~~ **FALSE ALARM**
- **Effort:** N/A
- **Status:** [x] Reviewed — not an issue
- **Date Fixed:** N/A
- **Notes:** Featurable is a trait (not a model). Mass assignment protection (`$fillable`/`$guarded`) is defined on the models using the trait, not on the trait itself. The trait's `update()` calls use the model's own protection.

### H5. Missing Controller References in Routes
- **Impact:** ~~Routes returning 404/500 errors~~ **No issues found**
- **Effort:** N/A
- **Status:** [x] Verified
- **Date Fixed:** Feb 23, 2026
- [x] Ran `php artisan route:list` — all 458 API routes compile successfully
- [x] No missing controller references found

### H6. Duplicate/Conflicting Migrations
- **Impact:** Migration failures, schema inconsistency
- **Effort:** 1-2 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Run `php artisan migrate:status` to check state
- [ ] Identify duplicate table modifications
- [ ] Consolidate or sequence migrations properly
- [ ] Run `php check_schema.php` for conflicts

### H7. Implement or Remove Stub Route Files
- **Impact:** ~~Confusion, dead code~~ **Resolved**
- **Effort:** Completed
- **Status:** [x] Completed
- **Date Fixed:** Feb 23, 2026

| Module | Decision | Notes |
|--------|----------|-------|
| social.php | [x] Keep | Populated with artist follow, comments, shares, activity routes |
| ecommerce.php | [x] Remove | Redundant — store.php + Store module covers this |
| engagement.php | [x] Keep | Populated with polls & awards routes |
| loyalty.php | [x] Remove | No implementation exists |
| wazuh.php | [x] Remove | No implementation; config/services.php entry retained |

### H8. Auth Route File Minimal
- **Impact:** ~~Incomplete authentication flow~~ **Resolved**
- **Effort:** Completed
- **Status:** [x] Completed
- **Date Fixed:** Feb 23, 2026
- [x] Added missing auth routes: logout, refresh, user (to routes/api/auth.php)
- [x] Added same routes to the /api/auth/* prefix group in api.php for frontend compatibility
- [x] Full auth flow: login, register, logout, refresh, user

---

## 🟡 MEDIUM Priority Issues

### M1. No Swagger/OpenAPI Documentation
- **Impact:** Developer experience, onboarding difficulty
- **Effort:** 1-2 days
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Install `darkaonline/l5-swagger`
- [ ] Add OpenAPI annotations to controllers
- [ ] Generate documentation
- [ ] Set up auto-generation on deploy

### M2. Inconsistent Pagination
- **Impact:** Performance issues on list endpoints
- **Effort:** 4-8 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Identify all list/index endpoints
- [ ] Add `->paginate()` to all collection queries
- [ ] Standardize page size (e.g., 20)
- [ ] Document pagination in API docs

### M3. Minimal Logging (24 occurrences only)
- **Impact:** Difficult debugging in production
- **Effort:** 4-8 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Add API request/response logging middleware
- [ ] Add structured logging for auth events
- [ ] Add logging for payment events
- [ ] Add logging for error scenarios
- [ ] Configure log levels per environment

### M4. TODO Comments in Code (3 found)
- **Impact:** Incomplete features
- **Effort:** 2-4 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] `NotificationController.php:124` — Persist to user_preferences table
- [ ] `ArtistApiController.php:697` — Create withdrawal request
- [ ] `TicketController.php:134` — Integrate ZengaPay API

### M5. Error Tracking Not Configured
- **Impact:** Missing production error visibility
- **Effort:** 1-2 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Install Sentry: `composer require sentry/sentry-laravel`
- [ ] Configure `SENTRY_LARAVEL_DSN` in `.env`
- [ ] Set sample rate for traces
- [ ] Test error reporting

### M6. Database Indexes for Performance
- **Impact:** Slow queries on large datasets
- **Effort:** 2-4 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Create migration for performance indexes
- [ ] Add indexes on `songs.status`, `songs.artist_id`, `songs.created_at`
- [ ] Add indexes on `users.email`, `users.role`
- [ ] Add composite indexes for common query patterns
- [ ] Review slow query log after deployment

### M7. Security Headers Enhancement
- **Impact:** ~~Missing browser security protections~~ **Already implemented**
- **Effort:** N/A
- **Status:** [x] Already complete
- **Date Fixed:** N/A (was never missing)
- [x] `SecurityHeadersMiddleware.php` is comprehensive:
  - `X-Content-Type-Options: nosniff` ✓
  - `X-Frame-Options: DENY` ✓
  - `X-XSS-Protection: 1; mode=block` ✓
  - `Strict-Transport-Security` (production only) ✓
  - `Referrer-Policy: strict-origin-when-cross-origin` ✓
  - `Permissions-Policy` ✓
  - `Content-Security-Policy` ✓
  - Middleware is globally appended in bootstrap/app.php

### M8. API Response Caching Headers
- **Impact:** Increased server load, slower responses
- **Effort:** 1-2 hours
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Add `Cache-Control` headers to GET endpoints
- [ ] Add `X-API-Version` header globally
- [ ] Configure ETags for resource endpoints

---

## 🟢 LOW Priority / Nice-to-Have

### L1. Laravel Telescope (Development)
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Install: `composer require laravel/telescope --dev`
- [ ] Run: `php artisan telescope:install && php artisan migrate`

### L2. API Usage Analytics
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Track endpoint usage frequency
- [ ] Monitor response times per endpoint
- [ ] Set up dashboards

### L3. API Deprecation Strategy
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Define deprecation policy
- [ ] Add deprecation headers to old endpoints
- [ ] Document sunset dates

### L4. API Changelog
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Create CHANGELOG.md
- [ ] Document all API changes going forward

### L5. Expand Notification System
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Currently 5 notification classes — expand for user engagement

### L6. Webhook Retry Documentation
- **Status:** [ ] Not started
- **Date Fixed:** ___
- [ ] Document retry mechanism
- [ ] Add dead-letter queue for failed webhooks

---

## � SUBSCRIPTION SYSTEM — Audit & Implementation Tracker

**Audit Date:** March 6, 2026
**Status:** Phase 2 Complete

### Bugs Found in Audit

| ID | Severity | Issue | Status | Date Fixed |
|----|----------|-------|--------|------------|
| SUB-CRIT-1 | 🚨 CRITICAL | `User::hasActiveSubscription()` does NOT check `expires_at` — expired subscriptions treated as active | [x] Fixed | Mar 6, 2026 |
| SUB-CRIT-2 | 🚨 CRITICAL | `SubscriptionPlan` model has 43 `$fillable` fields but DB schema only has ~12 columns — massive mismatch | [x] Fixed | Mar 6, 2026 |
| SUB-CRIT-3 | 🚨 CRITICAL | `UserSubscription` FK mismatch: model uses `subscription_plan_id`, migration has `plan_id` | [x] Fixed | Mar 6, 2026 |
| SUB-HIGH-1 | ⚠️ HIGH | `PaymentService::validatePaymentData()` rejects `mobile_money` — only accepts `zengapay` literal | [x] Fixed | Mar 6, 2026 |
| SUB-HIGH-2 | ⚠️ HIGH | `PaymentService::processSubscriptionPayment()` reads `$plan->price_local` which is NULL in DB | [x] Fixed | Mar 6, 2026 |
| SUB-HIGH-3 | ⚠️ HIGH | Zero rows in `subscription_plans` table — no seeder existed | [x] Fixed | Mar 6, 2026 |
| SUB-HIGH-4 | ⚠️ HIGH | `User::canDownload()` hardcodes 3/day — should read from plan limits | [x] Fixed | Mar 6, 2026 |
| SUB-HIGH-5 | ⚠️ HIGH | `getRemainingDownloadsAttribute()` uses hardcoded 10 and wrong query | [x] Fixed | Mar 6, 2026 |
| SUB-MED-1 | 🟡 MEDIUM | No public API to list subscription plans | [x] Fixed | Mar 6, 2026 |
| SUB-MED-2 | 🟡 MEDIUM | No API for user's current subscription status | [x] Fixed | Mar 6, 2026 |
| SUB-MED-3 | 🟡 MEDIUM | No subscribe endpoint — only cancel/extend existed | [x] Fixed | Mar 6, 2026 |
| SUB-MED-4 | 🟡 MEDIUM | No auto-renewal toggle endpoint | [x] Fixed | Mar 6, 2026 |
| SUB-MED-5 | 🟡 MEDIUM | `SubscriptionController::cancel()` used `$subscription->plan->name` (wrong relation) | [x] Fixed | Mar 6, 2026 |
| SUB-MED-6 | 🟡 MEDIUM | `SubscriptionController::cancel()` used `ends_at` instead of `expires_at` | [x] Fixed | Mar 6, 2026 |

### Phase 1 — Schema Alignment & Core API (Complete)

- [x] Migration `2026_03_06_120000_align_subscription_schema.php` — adds missing columns to both tables
- [x] `SubscriptionPlanSeeder.php` — 4 plans: Free (0 UGX), Premium (15K), Artist (25K), Label (100K)
- [x] Fix `User::hasActiveSubscription()` — now checks `expires_at->isFuture()`
- [x] Add `User::getActivePlan()` and `User::getPlanLimit()`
- [x] Fix `User::canDownload()` — reads limit from plan
- [x] Fix `User::getRemainingDownloadsAttribute()` — uses plan limits
- [x] Fix `PaymentService::validatePaymentData()` — accepts mobile_money/card/zengapay
- [x] Fix `PaymentService::processSubscriptionPayment()` — resolves amount from price_local or price_monthly or price
- [x] Rewrite `SubscriptionController` — plans(), current(), subscribe(), toggleAutoRenew(), cancel(), extend()
- [x] Add routes: GET /subscription-plans, GET /user/subscription, POST /subscriptions/subscribe, POST /subscriptions/toggle-auto-renew
- [ ] Run migration on production
- [ ] Seed plans on production

### Phase 2 — Subscription Lifecycle (Complete)

- [x] User profile API includes subscription status — `UserResource` enhanced with plan, tier, limits, days_remaining
- [x] Upgrade/downgrade between plans — `POST /subscriptions/change-plan` with pro-rata credit calculation
- [x] Admin subscription management — `AdminSubscriptionsController` with stats, list, show, grant, revoke, plan CRUD
- [x] Subscription history — `GET /user/subscription/history` with pagination
- [x] Admin routes secured with `auth:sanctum` + `role:admin,super_admin` + `admin.exceptions`

### Phase 3 — Auto-Renewal & Expiry (Complete)

- [x] `subscriptions:check-expired` Artisan command — `app/Console/Commands/CheckExpiredSubscriptions.php`
- [x] Auto-renew via ZengaPay `charge()` for `auto_renew=true` subscriptions (async: MoMo prompt → webhook confirms)
- [x] Expire subscriptions where `auto_renew=false` and `expires_at` is past
- [x] `pending_renewal` status for async payment window; stale renewals expired after 1 hour
- [x] Webhook integration — `Payment::markAsCompleted()` / `markAsFailed()` now complete/fail auto-renewals
- [x] `subscriptions:send-expiry-reminders` Artisan command — sends at 7d, 3d, 1d before expiry
- [x] `EXPIRING_SOON` notification type added to `SubscriptionNotification` (mail + push + DB)
- [x] Duplicate reminder prevention via metadata tracking (`expiry_reminder_7d`, `_3d`, `_1d`)
- [x] Scheduled in `routes/console.php` — expiry check at 6 AM EAT, reminders at 9 AM EAT
- [x] Both commands support `--dry-run` flag for safe testing

### Phase 4 — Feature Gating Refactor (Complete)

- [x] `canStream()`, `canUpload()`, `getMaxAudioQuality()` methods on User model
- [x] Audio quality gating — `MusicController::streamFile()` selects 128/320kbps file based on plan
- [x] Upload limit enforcement — `ArtistApiController::storeSong()` uses plan `max_uploads_per_month` with artist-level fallback
- [x] `isAdFree()`, `canAccessOffline()` methods on User model
- [x] `ad_free` and `offline_access` flags in UserResource subscription block (both tiers)
- [x] Download gating — `userCanDownloadTrack()` checks subscription + daily limit + purchases
- [x] `getMonthlyUploadLimit()` method — plan limit overrides artist.monthly_upload_limit

### Phase 5 — Frontend Integration (Complete ✅)

- [x] Pricing page with plan comparison (`/pricing`)
- [x] Subscribe flow: phone number → MoMo prompt → payment polling → confirmation
- [x] Subscription settings page (auto-renew toggle, cancel, change plan)
- [x] Plan badges in user profile
- [x] Hooks rewritten to match backend API endpoints exactly
- [x] Payment status polling via `GET /payments/status/{transactionId}`

---

## 📊 Progress Dashboard

### By Priority

| Priority | Total | Done | Remaining | % Complete |
|----------|-------|------|-----------|------------|
| 🚨 Critical | 7 | 5 | 2 | 71% |
| ⚠️ High | 13 | 12 | 1 | 92% |
| 🟡 Medium | 14 | 7 | 7 | 50% |
| 🟢 Low | 6 | 0 | 6 | 0% |
| **Total** | **40** | **24** | **16** | **60%** |

### By Category

| Category | Issues | Fixed | Notes |
|----------|--------|-------|-------|
| Security | 6 | 5 | Rate limiting enabled, CORS OK, SQL safe, mass assign OK, headers OK |
| Database | 3 | 0 | Migrations, indexes, conflicts still pending |
| Testing | 1 | 0 | Comprehensive API tests still needed |
| Documentation | 2 | 0 | Swagger, changelog |
| Routes | 3 | 3 | Stubs resolved, controller refs verified, auth complete |
| Performance | 3 | 0 | Pagination, caching, indexes |
| Monitoring | 3 | 0 | Logging, Sentry, analytics |
| Code Quality | 2 | 0 | TODOs, Telescope |
| Config | 1 | 1 | Sanctum published & configured |

---

## 🎯 Milestone Targets

### Milestone 1: Security Baseline (Target: ___)
- [x] C1 — Sanctum config
- [x] H1 — Rate limiting
- [x] H2 — CORS fix (was already correct)
- [x] H3 — Raw SQL review (safe, no injection risk)
- [x] H4 — Mass assignment fix (false alarm — trait, not model)
- [x] M7 — Security headers (already comprehensive)

### Milestone 2: Database Complete (Target: ___)
- [ ] C2 — All missing migrations created
- [ ] H6 — Migration conflicts resolved
- [ ] M6 — Performance indexes added

### Milestone 3: Routes & Controllers Clean (Target: ___)
- [x] C4 — Stub routes decided & handled
- [x] H5 — Broken controller refs fixed (none found)
- [x] H7 — Stub modules implemented or removed
- [x] H8 — Auth routes complete

### Milestone 4: Testing Foundation (Target: ___)
- [ ] C3 — Core API tests (auth, music, payments)
- [ ] 80%+ coverage on critical paths

### Milestone 5: Production Ready (Target: ___)
- [ ] M1 — Swagger docs
- [ ] M2 — Consistent pagination
- [ ] M3 — Comprehensive logging
- [ ] M5 — Sentry error tracking
- [ ] All critical & high issues resolved

---

## 📝 Resolution Log

_Record details of each fix here as they are completed._

| Date | Issue ID | Description | Commit/PR | Verified |
|------|----------|-------------|-----------|----------|
| Feb 23 | C1 | Published Sanctum config with TesoTunes domains | pending | ☐ |
| Feb 23 | H1 | Enabled API rate limiting in bootstrap/app.php | pending | ☐ |
| Feb 23 | H2 | Verified CORS already correctly configured | N/A | ☑ |
| Feb 23 | H3 | Reviewed all 38 DB::raw — all safe aggregations | N/A | ☑ |
| Feb 23 | H4 | Verified Featurable trait doesn't need $fillable | N/A | ☑ |
| Feb 23 | H5 | Verified all 458 routes compile cleanly | N/A | ☑ |
| Feb 23 | C4/H7 | Removed wazuh/ecommerce/loyalty stubs, populated social & engagement | pending | ☐ |
| Feb 23 | H8 | Added logout/refresh/user auth routes | pending | ☐ |
| Feb 23 | M7 | Verified SecurityHeadersMiddleware already comprehensive | N/A | ☑ |

---

## 🔗 Related Documents

- [API_DOCUMENTATION.md](API_DOCUMENTATION.md)
- [API_RESPONSE_STANDARDIZATION_TRACKER.md](API_RESPONSE_STANDARDIZATION_TRACKER.md)
- [DEPLOYMENT.md](DEPLOYMENT.md)
- [TEST_RESOLUTION_TRACKER.md](TEST_RESOLUTION_TRACKER.md)
