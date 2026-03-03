# TesoTunes Remediation Tracker
**Created:** March 1, 2026  
**Last Updated:** March 1, 2026

---

## Legend
- 🔴 Not Started
- 🟡 In Progress
- 🟢 Complete
- ⚫ Blocked / N/A

---

## Phase 1: Security Hardening (CRITICAL — Do First)

| # | Task | Priority | Status | File(s) | Notes |
|---|------|----------|--------|---------|-------|
| 1.1 | Remove unprotected admin routes from music.php | CRITICAL | 🟢 | `routes/api/music.php` | Wrapped in `auth:sanctum` + `role:admin,super_admin` + `admin.exceptions` |
| 1.2 | Add auth:sanctum + role:admin to admin store routes | CRITICAL | 🟢 | `routes/api.php` | SEC-CRIT-2 fix applied |
| 1.3 | Add role restriction to artist payout endpoint | CRITICAL | 🟢 | `routes/api.php` | SEC-CRIT-3 fix — `role:admin,super_admin` middleware |
| 1.4 | Set Sanctum token expiration | CRITICAL | 🟢 | `config/sanctum.php` | SEC-CRIT-4 fix — set to 1440 (24h) |
| 1.5 | Delete debug endpoint | CRITICAL | ⚫ | Frontend repo | N/A for Laravel backend |
| 1.6 | Remove token-leaking console.log | HIGH | ⚫ | Frontend repo | N/A for Laravel backend |
| 1.7 | Add rate limiting to login/register | HIGH | 🟢 | `routes/api/auth.php` | `throttle:login` and `throttle:register` applied |
| 1.8 | Add role:artist to artist routes | HIGH | 🟢 | `routes/api.php` | HIGH-5 fix — `role:artist,admin,super_admin` |
| 1.9 | Sanitize User $fillable array | HIGH | 🟢 | `app/Models/User.php` | HIGH-4 fix — privilege fields commented out |
| 1.10 | Add ownership verification to refund endpoint | HIGH | 🟢 | `PaymentController.php` | HIGH-7 fix — user_id check + admin bypass |
| 1.11 | Escape LIKE wildcard characters | HIGH | � | 15+ files fixed | Created `escape_like()` helper in security_helpers.php; applied across all services, repos, controllers, models |
| 1.12 | Protect admin dashboard routes | CRITICAL | 🟢 | `routes/api.php` | Wrapped in `auth:sanctum` + `role:admin,super_admin` + `admin.exceptions` |

### Phase 1 Progress: 10/12 complete (2 N/A frontend)

---

## Phase 2: Stability & Error Handling

| # | Task | Priority | Status | File(s) | Notes |
|---|------|----------|--------|---------|-------|
| 2.1-2.11 | Add error handling to all admin controllers | HIGH | 🟢 | All admin controllers | Solved via `HandleAdminExceptions` middleware + `HandlesApiErrors` trait |
| 2.12-2.14 | Replace raw DB in admin controllers | MEDIUM | 🟢 | 3 admin controllers | Already using Eloquent — no raw DB::table() found |
| 2.15 | Standardize JSON response format | MEDIUM | 🔴 | All controllers | Use ApiResponse trait consistently |
| 2.16 | Fix Order model table name | HIGH | 🟢 | `app/Modules/Store/Models/Order.php` | Changed to `store_orders` |
| 2.17 | Create podcast_listens migration | HIGH | 🟢 | `database/migrations/` | DB-CRIT-2 fix |
| 2.18 | Create podcast_subscriptions migration | HIGH | 🟢 | `database/migrations/` | DB-CRIT-3 fix |
| 2.19 | Add missing database indexes | MEDIUM | 🟢 | `2026_03_01_000003_add_missing_indexes_and_soft_deletes.php` | Songs, albums, artists, payments, users indexes |
| 2.20 | Fix soft-delete column mismatches | MEDIUM | 🟢 | Same migration as 2.19 | notifications, feed_items, campaign_updates, sacco_members |
| 2.21 | Fix PlayHistoryFactory | MEDIUM | 🟢 | `database/factories/PlayHistoryFactory.php` | Aligned with actual schema |
| 2.22 | Fix DownloadFactory | MEDIUM | 🟢 | `database/factories/DownloadFactory.php` | Fixed polymorphic references |

### Phase 2 Progress: 8/10 complete (2.1-2.14, 2.16-2.22 done; 2.15 remaining)

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
| 1. Security | 12 | 10 (+2 N/A) | 0 (backend complete) |
| 2. Stability | 10 | 8 | 2 (response format) |
| 3. Features | 13 | 0 | 13 |
| 4. Cleanup | 8 | 0 | 8 |
| **TOTAL** | **43** | **18** | **23** |

### Overall Remediation Progress: ~42% (Phase 1 security 100% complete for backend)
