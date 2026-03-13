# Schema Target Map

**Updated:** March 13, 2026  
**Scope:** `tesotunes-api`

## Goal

Define the target ownership boundaries for the platform schema so future migrations split concerns instead of extending the `users` table indefinitely.

## Current High-Level Domain Map

| Domain | Current Tables | Status | Notes |
|------|----------------|--------|-------|
| Identity and auth | `users`, `personal_access_tokens`, `password_reset_tokens`, `user_roles` | Mixed | Core auth still depends heavily on `users`, while role state is split between `users.role` and `user_roles`. |
| Sessions and verification | `sessions`, `email_verification_tokens`-style Laravel flow, `users.email_verified_at`, `users.phone_verified_at` | Mixed | Session state exists, but verification flags still live on `users`. |
| Profile and preferences | `users`, `user_settings` | Partial | `user_settings` exists, but profile fields and some preferences are still duplicated on `users`. |
| Artist/KYC onboarding | `users`, `artists`, artist application flow, KYC uploads referenced in code | Fragile | KYC fields still live on `users`; model references for normalized KYC records are incomplete. |
| Wallet and credits | `users`, `user_credits`, `credit_transactions`, `payments` | Mixed | Wallet balances still read from `users.ugx_balance` and `users.credits` in some flows even though wallet tables exist. |
| Referrals and growth | `users` | Not normalized | Referral code, referrer link, counters, and timestamps still live on `users`. |
| Notifications and privacy | `notifications`, `user_settings`, `users.notification_preferences` | Mixed | Notification delivery uses the morph-based notifications table, but preferences are split across `users` and `user_settings`. |

## Target Ownership Boundaries

### 1. `users` should keep only identity and account-state essentials

Target fields to remain on `users`:

- primary identifiers: `id`, `name`, `username`, `email`, `password`
- verification timestamps required by auth: `email_verified_at`, `phone_verified_at`
- lightweight account status: `status`, `is_active`, `deleted_at`
- audit essentials: `last_login_at`, `last_admin_login_at`
- compatibility fields during transition: `role` only until pivot-role migration is complete

### 2. `user_profiles` should own human-facing profile data

Target contents:

- `display_name`, `first_name`, `last_name`, `bio`
- `avatar`, `banner`, `gender`
- `country`, `city`, `timezone`, `date_of_birth`, `language`
- social links
- profile completion caches if we still want denormalized completion metrics

### 3. `user_preferences` or expanded `user_settings` should own UI and notification preferences

Target contents:

- theme and client/UI choices
- autoplay/audio quality settings
- privacy settings
- notification channel preferences
- public profile and visibility flags

### 4. `user_security_profiles` should own sensitive auth-adjacent settings

Target contents:

- 2FA flags, secret, recovery codes
- last security challenge metadata
- security policy flags that are not part of identity itself

### 5. `kyc_documents` plus `artist_applications` should own KYC and onboarding evidence

Target contents:

- uploaded KYC file paths
- document type and review status
- reviewer and review timestamps
- rejection reasons and notes

The current KYC fields on `users` should become compatibility mirrors only during migration.

### 6. `wallet_accounts` or expanded wallet tables should own balances

Target contents:

- wallet balance in fiat
- credits balance
- ledger-driven state from transactions rather than mutable fields on `users`

`users.credits` and `users.ugx_balance` should become deprecated compatibility reads only, then removed.

### 7. `referral_profiles` should own referral metadata

Target contents:

- referral code
- `referrer_id`
- referral counters
- growth attribution timestamps

## Immediate Risks Found During Baseline

- `User` references normalized models like `UserVerification` and `KYCDocument`, but those models are not present at the expected app paths.
- `user_settings` exists but does not cleanly match every settings shape currently read or written by controllers.
- wallet-related APIs still read balances directly from `users` despite `user_credits` and transaction tables existing.
- the `users` table is still a source of truth for KYC, payout, referrals, preferences, and profile state.

## Recommended Phase 3 Execution Order

1. Document current `users` field ownership and mark target destination per column.
2. Normalize profile/preferences/security/KYC/wallet boundaries with additive tables only.
3. Backfill existing `users` data into the new tables.
4. Switch reads to the new tables behind accessors/services.
5. Remove stale direct-column writes from controllers and services.
6. Only after read/write parity is stable, deprecate and eventually remove old `users` columns.
