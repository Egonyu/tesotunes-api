# Schema Baseline — Production Drift Audit & Reconciliation

**Date:** 2026-06-11
**Prod snapshot:** `tesotunes_prod_20260611.sql.gz` (MariaDB 11.4, 183 tables, ~103 MB, 137 users / 237 songs)
**Reference:** `php artisan migrate:fresh` from the 42 migration files (MySQL, `tesotunes_schema_ref`)
**Repair:** `database/migrations/2026_06_11_000001_reconcile_production_schema_drift.php`

## Summary

The live production database had drifted from the migration-defined schema even though
`migrate:status` reported every migration as Ran. The migration set itself is healthy
(it builds a complete schema from scratch); the drift was entirely on the production side,
accumulated through ad-hoc fixes before the migration squash.

| Drift class | Found | After repair |
|---|---|---|
| Tables missing in prod | 3 (`stores`, `product_categories`, `store_category_pivot`) | 0 |
| Tables in old shape (all empty) | 5 (`store_products/orders/order_items/carts/cart_items`, 27–39 cols missing each) | 0 (dropped + recreated) |
| Columns missing in prod | ~110 (incl. 30 on `songs`) | 0 |
| Real type/nullability mismatches | 9 | 0 |
| Indexes missing in prod | 21 (incl. `promoter_profiles` unique constraints) | 0 |
| Legacy extra columns in prod | 22 | 14 kept (documented below), 8 dropped (empty `events` + recreated store tables) |

**144 of 153 raw type mismatches were MariaDB/MySQL dialect artifacts** (`json` vs
`longtext`) — not real drift. Only 9 were genuine.

## Verification performed (all against local copies, never prod)

1. Repair migration on restored prod snapshot → re-diff shows zero missing tables/columns/indexes, zero real type mismatches.
2. Repair migration on the already-correct reference schema → clean no-op.
3. Repair migration re-run on the already-repaired snapshot → clean no-op (idempotent).

## Deliberate decisions

- **Kept 14 legacy extra columns** on populated tables rather than risk data loss:
  `isrc_codes` (isrc_code, formatted_isrc, generated_at, album_id), `user_follows` (3),
  `artist_revenues` (song_id, album_id), `songs` (isrc_code, preview_url),
  `playlists.follower_count`, `users` (1), `user_subscriptions` (1).
  Revisit when the owning module is refactored (Phases 2–4).
- **Dropped 7 legacy columns from `events`** (start_date/end_date/price/…) — table is
  empty in prod; the migration refuses to drop them if rows exist.
- **Drop-and-recreate is gated three ways:** old-generation sentinel column check,
  table-must-be-empty check (throws otherwise), and `hasTable`/`hasColumn`/`hasIndex`
  guards on every operation so the migration is a no-op everywhere but drifted prod.
- The `loyalty_rewards.product_id → store_products` FK is detached before the drop and
  re-attached to match the reference definition.

## Engine mismatch (open item)

Production runs **MariaDB 11.4**; local dev runs **MySQL 9.4**. Laravel papers over most
differences, but JSON columns, collations, and index behaviors differ. Recommendation:
run MariaDB 11.4 locally (Docker) for anything schema-sensitive, and treat the prod
snapshot restore as the canonical pre-deploy test target.

## Safe forward-migration workflow (now in place)

1. **Backup before migrate** — `deploy-production.yml` now dumps the DB
   (`/var/www/backup/db/pre-migrate-<ts>.sql.gz`, 14-day retention) and aborts the deploy
   if the dump fails, before `php artisan migrate --force` runs.
2. **Test against prod schema first** — for any schema-touching change: restore the latest
   snapshot locally (`.backups/db/` in the project root, or pull a fresh one via
   `ssh tesotunes`), run `php artisan migrate` against it with `DB_DATABASE=tesotunes_prod_snapshot`,
   and re-run the drift diff (queries embedded in this doc's history; see also
   `scripts/` analysis helpers).
3. **Never trust `migrate:status` alone** — it reports bookkeeping, not reality. The
   information_schema diff is the ground truth check.

## Rollout plan for the repair migration

1. Deploy ships the migration through the normal pipeline; the new backup step runs first.
2. On prod it will: create the 3 missing tables, recreate the 5 empty store tables,
   add the missing columns/indexes, fix the 9 type drifts, drop the 7 empty-events columns.
3. Post-deploy check: `php artisan migrate:status | tail` plus a smoke call to
   `/api/store/public/products` (was 500-ing on the missing `stores` table joins).
