# API Privileged Route Review Log

**Updated:** March 13, 2026  
**Scope:** `tesotunes-api`

## Purpose

Track the current review status of high-risk API route groups and document which public mutation routes are intentional exceptions.

## Route Group Review

| Area | Access Model | Review Status | Notes |
|------|--------------|---------------|-------|
| Auth | Public + `auth:sanctum` | Reviewed | Canonical surface is `/api/auth/*`; duplicate root auth routes removed. |
| Webhooks | Public + signature/rate limit | Reviewed | Payment and distribution webhooks are public by design, rate limited, and now covered for signature/idempotency. |
| Admin | `auth:sanctum` + `role:admin,super_admin` + controller checks | Reviewed | Sensitive admin controllers now enforce authorization internally as defense in depth. |
| Artist | `auth:sanctum` + `role:artist,admin,super_admin` | Partially reviewed | Route protection is present; deeper controller-by-controller parity review remains for non-admin artist operations. |
| Store seller promotions | `auth:sanctum` + `role:artist,admin,super_admin` | Reviewed | Seller promotion mutations no longer ride on plain authenticated access. |
| Mobile legacy/v1 | Sanctum + deprecated compatibility routes | Reviewed | Legacy `v1` protected routes now use `auth:sanctum` and return deprecation/sunset headers for migration pressure. |

## Intentional Public Mutation Routes

These endpoints are currently allowed without login and are considered acceptable exceptions because they support public UX or third-party callbacks.

| Route | Protection | Reason |
|------|------------|--------|
| `POST /api/auth/login` | `throttle:login` | Public authentication entrypoint |
| `POST /api/auth/register` | `throttle:register` | Public registration entrypoint |
| `POST /api/auth/forgot-password` | `throttle:login` | Account recovery |
| `POST /api/auth/reset-password` | `throttle:login` | Account recovery |
| `POST /api/auth/email/verify` | `throttle:login` | Email verification callback |
| `POST /api/ads/impression` | `throttle:ad-tracking` | Public analytics event |
| `POST /api/ads/click` | `throttle:ad-tracking` | Public analytics event |
| `POST /api/theme` | `throttle:theme` | Guest theme preference persistence |
| `POST /api/webhooks/zengapay` | `webhook.rate_limit` + provider signature | External payment callback |
| `POST /api/webhooks/distribution/{platform}` | `webhook.rate_limit` + HMAC signature + replay cache | External distribution callback |

## Follow-up Items

- Retire or consolidate the `api/v1` route set so duplicated legacy endpoints do not continue to drift.
- Finish the artist-controller authorization parity review.
- Expand governance tests if new public mutation routes are introduced.
- Decide whether to expose a request correlation ID in API error payloads and logs for faster incident tracing.
