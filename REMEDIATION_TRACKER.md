# TesoTunes Remediation Tracker
**Created:** March 1, 2026  
**Last Updated:** March 1, 2026

---

## Legend
- 🔴 Not Started
- 🟡 In Progress
- 🟢 Complete
- ⚫ Blocked

---

## Phase 1: Security Hardening (CRITICAL — Do First)

| # | Task | Priority | Status | File(s) | Notes |
|---|------|----------|--------|---------|-------|
| 1.1 | Remove unprotected admin routes from music.php | CRITICAL | 🟢 | `routes/api/music.php` lines 42-68 | Secured with auth:sanctum + role:admin,super_admin |
| 1.2 | Add auth:sanctum + role:admin to admin store routes | CRITICAL | 🟢 | `routes/api.php` lines 224-261 | Wrapped store admin group in middleware |
| 1.3 | Add role restriction to artist payout endpoint | CRITICAL | 🟢 | `PaymentController.php` line 87 | Restricted to role:admin,super_admin |
| 1.4 | Set Sanctum token expiration | CRITICAL | 🟢 | `config/sanctum.php` line 51 | Set to `1440` (24 hours) |
| 1.5 | Delete debug endpoint | CRITICAL | 🔴 | `src/app/api/auth/debug/route.ts` | Delete the entire file |
| 1.6 | Remove token-leaking console.log | HIGH | 🔴 | `src/lib/auth.ts` lines 204-228 | Remove or replace with non-sensitive logging |
| 1.7 | Add rate limiting to login/register | HIGH | 🟢 | `routes/api/auth.php` | Applied `throttle:5,1` to login, `throttle:3,60` to register |
| 1.8 | Add role:artist to artist routes | HIGH | 🟢 | `routes/api.php` lines 86-120 | Added `role:artist` middleware to group |
| 1.9 | Sanitize User $fillable array | HIGH | 🟢 | `app/Models/User.php` | Removed: `is_active`, `is_premium`, `credits`, `ugx_balance`, `permissions`, `email_verified_at` from $fillable |
| 1.10 | Add ownership verification to refund endpoint | HIGH | 🟢 | `PaymentController.php` line 66 | Added `payment.user_id === auth.user.id` check |
| 1.11 | Escape LIKE wildcard characters | HIGH | 🔴 | 30+ controller/service files | Create helper: `escapeLike($str)` → `addcslashes($str, '%_')` |
| 1.12 | Protect admin dashboard routes | CRITICAL | 🔴 | `routes/api.php` lines 282-286 | Move back inside `auth:sanctum` + `role:admin` (fix frontend to send token properly) |

### Phase 1 Progress: 7/12 complete

---

## Phase 2: Stability & Error Handling

| # | Task | Priority | Status | File(s) | Notes |
|---|------|----------|--------|---------|-------|
| 2.1 | Add try-catch to AdminUsersController | HIGH | 🔴 | `AdminUsersController.php` | All methods |
| 2.2 | Add try-catch to AdminSongsController | HIGH | 🔴 | `AdminSongsController.php` | All methods |
| 2.3 | Add try-catch to AdminAlbumsController | HIGH | 🔴 | `AdminAlbumsController.php` | All methods |
| 2.4 | Add try-catch to AdminEventsController | HIGH | 🔴 | `AdminEventsController.php` | All methods |
| 2.5 | Add try-catch to AdminAwardsController | HIGH | 🔴 | `AdminAwardsController.php` | All methods |
| 2.6 | Add try-catch to AdminPlaylistsController | HIGH | 🔴 | `AdminPlaylistsController.php` | All methods |
| 2.7 | Add try-catch to AdminGenresController | HIGH | 🔴 | `AdminGenresController.php` | All methods |
| 2.8 | Add try-catch to AdminPollsController | HIGH | 🔴 | `AdminPollsController.php` | All methods |
| 2.9 | Add try-catch to AdminRolesController | HIGH | 🔴 | `AdminRolesController.php` | All methods |
| 2.10 | Add try-catch to AdminSettingsController | HIGH | 🔴 | `AdminSettingsController.php` | All methods |
| 2.11 | Add try-catch to remaining admin controllers | HIGH | 🔴 | 4 more controllers | Podcasts, Notifications, Reports, Analytics |
| 2.12 | Replace raw DB in AdminArtistsController | MEDIUM | 🔴 | `AdminArtistsController.php` | Convert DB::table() → Eloquent across all methods |
| 2.13 | Replace raw DB in SaccoApiController | MEDIUM | 🔴 | `SaccoApiController.php` | Convert DB::table() → Eloquent |
| 2.14 | Replace raw DB in StoreApiController | MEDIUM | 🔴 | `StoreApiController.php` | Convert DB::table() → Eloquent |
| 2.15 | Standardize JSON response format | MEDIUM | 🔴 | All controllers | Use ApiResponse trait consistently |
| 2.16 | Fix Order model table name | HIGH | 🔴 | `app/Modules/Store/Models/Order.php` | Change `$table = 'orders'` → `$table = 'store_orders'` |
| 2.17 | Create podcast_listens migration | HIGH | 🔴 | `database/migrations/` | Create migration matching code expectations |
| 2.18 | Create podcast_subscriptions migration | HIGH | 🔴 | `database/migrations/` | Create migration matching code expectations |
| 2.19 | Add missing database indexes | MEDIUM | 🔴 | New migration file | songs: primary_genre_id, user_id, play_count, release_date; albums: primary_genre_id; events: start_date |
| 2.20 | Fix soft-delete column mismatches | MEDIUM | 🔴 | 4 migration files | Add `deleted_at` to: notifications, feed_items, campaign_updates, sacco_members |
| 2.21 | Fix PlayHistoryFactory | MEDIUM | 🔴 | `database/factories/PlayHistoryFactory.php` | Match actual DB columns |
| 2.22 | Fix DownloadFactory | MEDIUM | 🔴 | `database/factories/DownloadFactory.php` | Fix polymorphic references |

### Phase 2 Progress: 0/22 complete

---

## Phase 3: Feature Completion

| # | Task | Priority | Status | File(s) | Notes |
|---|------|----------|--------|---------|-------|
| 3.1 | Build reset password confirmation page | HIGH | 🔴 | Frontend: `src/app/(auth)/reset-password/` | |
| 3.2 | Build email verification page | HIGH | 🔴 | Frontend: `src/app/(auth)/verify-email/` | |
| 3.3 | Replace mock data in admin podcasts page | MEDIUM | 🔴 | Frontend: admin podcasts page | Wire to real API |
| 3.4 | Replace mock data in admin SACCO member detail | MEDIUM | 🔴 | Frontend: admin SACCO member detail | Wire to real API |
| 3.5 | Replace mock data in polls pages | MEDIUM | 🔴 | Frontend: polls page + detail | Remove hardcoded fallback |
| 3.6 | Replace mock data in forums pages | MEDIUM | 🔴 | Frontend: forums pages | Remove hardcoded fallback |
| 3.7 | Replace mock data in ojokotau/crowdfunding | MEDIUM | 🔴 | Frontend: ojokotau pages | Need backend API first |
| 3.8 | Build admin store create/edit forms | MEDIUM | 🔴 | Frontend: categories, discounts, promotions, shipping | 4 sub-pages |
| 3.9 | Add real charts to admin analytics | LOW | 🔴 | Frontend: admin analytics page | Chart.js or Recharts |
| 3.10 | Wire RoleController to routes | HIGH | 🔴 | `routes/api.php` | 267-line controller exists but no routes |
| 3.11 | Wire UserManagementController to routes | HIGH | 🔴 | `routes/api.php` | 434-line controller exists but no routes |
| 3.12 | Build Ojokotau backend module | LOW | 🔴 | `app/Modules/Ojokotau/` | Currently skeleton |
| 3.13 | Implement actual email verification flow | HIGH | 🔴 | Backend AuthController + frontend | Currently auto-verified on register |

### Phase 3 Progress: 0/13 complete

---

## Phase 4: Code Cleanup

| # | Task | Priority | Status | File(s) | Notes |
|---|------|----------|--------|---------|-------|
| 4.1 | Remove 4 orphaned Backend/ controllers | LOW | 🔴 | `app/Http/Controllers/Backend/` | Zero routes, dead code |
| 4.2 | Audit & remove/wire remaining 9 orphaned controllers | LOW | 🔴 | Multiple files | Controllers with no routes |
| 4.3 | Remove duplicate auth controller | MEDIUM | 🔴 | Either `Api/AuthController` or `Api/Auth/AuthController` | Keep one, remove other |
| 4.4 | Remove console.log/warn statements | MEDIUM | 🔴 | 16 frontend files | Keep console.error in catch blocks |
| 4.5 | Replace `: any` types | LOW | 🔴 | 14 frontend files | 28 instances |
| 4.6 | Remove test upload endpoint | MEDIUM | 🔴 | `routes/api.php` line 594 | Used for testing, should not be in prod |
| 4.7 | Remove 3 no-op migrations | LOW | 🔴 | `database/migrations/` | Empty up() methods |
| 4.8 | Create missing factories (~25 models) | LOW | 🔴 | `database/factories/` | For test coverage |

### Phase 4 Progress: 0/8 complete

---

## Summary

| Phase | Tasks | Complete | Remaining |
|-------|-------|----------|-----------|
| 1. Security | 12 | 7 | 5 |
| 2. Stability | 22 | 0 | 22 |
| 3. Features | 13 | 0 | 13 |
| 4. Cleanup | 8 | 0 | 8 |
| **TOTAL** | **55** | **7** | **48** |

### Overall Remediation Progress: 13%
