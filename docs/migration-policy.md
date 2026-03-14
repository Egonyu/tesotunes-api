# Migration Policy

- Treat the `0001_01_01_00000*_create_*_tables.php` baseline files as the source of truth for fresh installs.
- The only current non-baseline exception is `2026_02_23_090003_create_telescope_entries_table.php`, which stays vendor-shaped because it mirrors Laravel Telescope's published schema.
- Add forward-only migrations after the baseline. Do not reintroduce `fix_*`, `add_missing_*`, `ensure_*`, `create_missing_*`, or `*_sync` migration patterns.
- Keep each migration scoped to one concern or bounded context.
- Prefer explicit schema definitions over `Schema::hasTable()` and `Schema::hasColumn()` checks in normal forward migrations.
- Run `php artisan migrate:fresh` regularly in local and test environments to validate the entire chain.
- If a compatibility bridge is needed later, add it as a clearly named forward migration instead of editing the baselines.
- Baseline migrations must not contain defensive schema repair logic. Fresh installs should be described directly, not patched into shape.
