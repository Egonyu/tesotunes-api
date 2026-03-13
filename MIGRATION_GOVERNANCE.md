# Migration Governance

**Updated:** March 13, 2026

## Rule Set

- One migration should change one concern where practical.
- Additive migrations come before destructive cleanup.
- Backfills should be explicit and reversible where possible.
- Large rescue or sync migrations are recovery tools, not normal feature workflow.
- Schema ownership must be documented before adding new user-domain columns.

## Recovery-Only Migrations

The following migration is considered recovery-only and should not be used as the model for future feature work:

- `database/migrations/2026_02_16_000001_comprehensive_schema_sync.php`

Reason:

- it creates many tables and columns across unrelated domains
- it blurs schema ownership
- it makes review harder
- it encourages “just add it there” behavior instead of bounded schema changes

## Required Review Questions

- Which bounded context owns this schema change?
- Is this an additive change or a cleanup change?
- Is there a backfill plan?
- Does the change introduce a second source of truth?
- Are indexes and foreign keys included deliberately?
- Do model/resource/controller reads need a compatibility bridge?

## Phase 3 Immediate Policy

- No new user-adjacent fields should be added to `users` unless the team documents why a dedicated table is not the right home.
- New profile, preference, security, KYC, wallet, and referral work should target dedicated tables first.
