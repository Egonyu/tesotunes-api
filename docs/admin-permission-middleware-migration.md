# Admin Route Permission Middleware Migration Plan

This document tracks the migration of admin API endpoints from broad role checks to explicit permission checks.

## Goal

- Keep `auth:sanctum` and `admin.exceptions` middleware.
- Replace or supplement `role:admin,super_admin` with `permission:*` for least-privilege enforcement.
- Migrate endpoint groups incrementally to reduce risk.

## Mapping (Initial)

- `GET /api/admin/users*` -> `permission:user.view`
- `POST|PUT|DELETE /api/admin/users*` -> `permission:user.create`, `permission:user.edit`, `permission:user.delete`
- `GET /api/admin/roles*` -> `permission:manage-roles|admin.settings|admin.users`
- `POST|PUT|DELETE /api/admin/roles*` -> `permission:manage-roles`
- `GET /api/admin/payments*` -> `permission:payment.view|payment.manage`
- Mutating payment endpoints -> `permission:payment.manage|payment.approve`
- `GET /api/admin/catalog*` -> `permission:catalog.view`
- Catalog claim review actions -> `permission:catalog.claim.review`
- `GET /api/admin/analytics*` -> `permission:view-analytics|admin.reports`
- `GET /api/admin/reports*` -> `permission:view-reports|admin.reports`
- `GET|PUT /api/admin/settings` -> `permission:manage-settings|admin.settings`
- `GET /api/admin/audit-logs` -> `permission:admin.settings`
- `GET|POST|PUT|DELETE /api/admin/feature-flags*` -> `permission:admin.settings`

## Suggested Rollout

1. Add permission middleware to read-only routes first (`GET` endpoints).
2. Add permission middleware to mutating endpoints (`POST/PUT/DELETE`) with tighter permissions.
3. Run smoke tests for each migrated group:
   - authorized admin with expected permission -> `200`
   - admin without required permission -> `403`
   - unauthenticated -> `401`
4. Remove redundant role checks only after all tests pass and monitoring confirms no regressions.

## Notes

- `super_admin` still retains full access through `User::hasPermission()` returning true.
- Keep route naming and payload contracts unchanged during migration.
- Log denied permission attempts with route + permission for auditability.
