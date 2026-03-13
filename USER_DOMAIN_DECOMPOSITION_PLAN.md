# User Domain Decomposition Plan

**Updated:** March 13, 2026

## Objective

Break the `users` domain into smaller bounded contexts without introducing regressions in auth, profile, artist onboarding, or wallet behavior.

## Current Problem

`users` currently mixes:

- authentication identity
- profile fields
- KYC document metadata
- payout/mobile money fields
- wallet and credits balances
- referral state
- notification preferences
- role compatibility state

That makes migrations risky, encourages model bloat, and creates conflicting sources of truth.

## Proposed Bounded Contexts

### Identity

Source of truth:

- `users`

Responsibilities:

- login credentials
- email identity
- account active/suspended state
- auth timestamps

### Profile

Target table:

- `user_profiles`

Responsibilities:

- public-facing profile information
- demographic and location fields
- social links
- avatar/banner assets

### Preferences

Target:

- extend/standardize `user_settings`

Responsibilities:

- theme
- playback defaults
- privacy flags
- notification settings

### Security

Target table:

- `user_security_profiles`

Responsibilities:

- two-factor secret state
- recovery codes
- security toggles that do not belong on the identity root

### KYC and Artist Verification

Target:

- `kyc_documents`
- `artist_applications` / existing artist verification tables/services

Responsibilities:

- document storage metadata
- review workflow
- rejection reasons and review notes

### Wallet and Credits

Target:

- keep `user_credits` as wallet/credits account root or replace with clearer wallet naming
- continue using `credit_transactions`
- move fiat wallet state away from `users`

Responsibilities:

- balances
- ledger
- wallet-specific summaries

### Referrals

Target table:

- `user_referrals`

Responsibilities:

- referral code generation
- attribution links
- counts and timestamps

## Proposed First Additive Migration Set

### Migration A: create `user_profiles`

Fields to move first:

- `display_name`
- `first_name`
- `last_name`
- `bio`
- `avatar`
- `banner`
- `gender`
- `country`
- `city`
- `timezone`
- `date_of_birth`
- `language`
- `instagram_url`
- `twitter_url`
- `facebook_url`
- `youtube_url`
- `tiktok_url`

### Migration B: create `user_security_profiles`

Fields to move first:

- `two_factor_enabled`
- `two_factor_secret`
- `two_factor_recovery_codes`

### Migration C: create `user_referrals`

Fields to move first:

- `referral_code`
- `referrer_id`
- `referral_count`
- `referred_at`

### Migration D: backfill from `users`

Goals:

- copy data into additive tables
- keep existing code working through fallback accessors

## Read/Write Transition Rules

1. Add tables first.
2. Backfill from `users`.
3. Update services/resources/accessors to prefer new tables.
4. Keep write-through compatibility only where necessary.
5. Remove controller-level direct writes to deprecated `users` columns.
6. Remove deprecated columns only after one stable release cycle.

## Known Code Hotspots To Refactor Early

- `app/Models/User.php`
- `app/Services/ProfileCompletionService.php`
- `app/Http/Controllers/Api/NotificationController.php`
- `app/Http/Controllers/Api/PaymentController.php`
- `app/Services/Auth/ArtistVerificationService.php`

## Success Criteria

- `User` no longer owns public profile, KYC, referral, and wallet details directly.
- New migrations are additive and bounded to one concern each.
- Controllers stop treating `users` as the write target for every user-adjacent concern.
- Read paths are stable before any destructive schema cleanup begins.
