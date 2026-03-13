# API Access Matrix

Updated: March 13, 2026

## Route Classes

| Access level | Primary route groups | Expected middleware |
|---|---|---|
| Public | `/api/health*`, `/api/auth/login`, `/api/auth/register`, `/api/events/*`, public music/content/discovery routes, payment/distribution webhooks | none or `throttle:*` / `webhook.rate_limit` |
| Authenticated user | `/api/auth/logout`, `/api/auth/user`, `/api/player/*`, `/api/notifications/*`, `/api/payments/*`, `/api/payouts/*`, `/api/store/*`, `/api/posts/*`, `/api/comments/*`, `/api/users/*`, `/api/feed/*`, `/api/credits/*`, `/api/uploads/*` | `auth:sanctum` |
| Artist or admin | `/api/artist/*` dashboard/song/profile routes, `/api/artist/events/*`, `/api/artist/loyalty-cards/*`, `/api/store/seller/promotions/*` | `auth:sanctum` + `role:artist,admin,super_admin` |
| Admin only | `/api/admin/*`, `/api/admin/store/*`, `/api/admin/loyalty/*`, `/api/admin/distribution-performance/*`, admin-only payment/subscription actions | `auth:sanctum` + `role:admin,super_admin` + `admin.exceptions` where configured |
| Member-scoped | `/api/sacco/*` | `auth:sanctum` + `sacco.member.api` |
| Mobile | `/api/mobile/*`, `/api/v1/*` mobile/public/player groups | `auth:sanctum` or `api.rate_limit:*` depending on route |
| Webhooks | `/api/webhooks/*`, `/api/payments/webhook`, `/api/webhooks/distribution/*` | `webhook.rate_limit` plus provider validation inside handlers |

## Retired Or Legacy Surfaces

- Retired on March 13, 2026: legacy web `/auth/login` and `/auth/register` routes in `routes/auth.php`.
- Canonical auth surface is now `/api/auth/*`.
- `routes/api/v1/api.php` still contains a versioned `/auth/login` entry and should be reviewed before broader v1 cleanup.

## Findings Closed In This Phase

- Closed: duplicate non-API web auth surface removed.
- Closed: seller promotion management no longer sits behind plain authentication only.
- Closed: auth throttling now has explicit backend coverage for login and register.
- Closed: admin and artist web shells no longer rely on client-only layout checks.

## Remaining Review Targets

- Review authenticated-but-sensitive distribution routes for least-privilege middleware consistency.
- Review all `/api/admin/*` controllers for controller-level authorization parity with route middleware.
- Review webhook handlers for signature validation and idempotency coverage.
- Review mobile and `v1` route files for overlap, stale compatibility endpoints, and naming consistency.
