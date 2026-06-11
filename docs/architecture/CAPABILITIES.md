# Account Capabilities — One Account, Many Roles

**Status:** Phase 3 of the platform rebuild.

## Problem

A user joins TesoTunes and may, over time, become any combination of artist, store
seller, event organizer, promoter, or label. Before this layer, each path used a
different authorization mechanism:

| Capability | Old mechanism |
|---|---|
| Artist | `role = 'artist'` on users + `artists.status` |
| Seller | implicit — owning a `stores` row |
| Organizer | `event_organizer` flag buried in the settings/privacy JSON, admin-set only, no onboarding |
| Promoter | `promoter_profiles` row (V2) AND a duplicate store-module profile (V1) |
| Label | role name only, nothing behind it |

## Design

`user_capabilities` is the **authorization source of truth**: one row per
(user, capability) with a uniform lifecycle and audit trail:

```
apply (pending) ──> grant (granted) ──> suspend ⇄ grant
        │                  │
        └──> reject        └──> revoke      rejected/revoked may re-apply
```

- **Domain profiles stay where they are** (artists, stores, promoter_profiles) and are
  linked via the grant's `profile` morph. The grant answers *"may this user act as X?"*;
  the profile holds *what their X looks like*.
- **The KYC gate is shared**: `CapabilityService::grant(..., requireKyc: true)` refuses
  to grant until `KycService::currentStatus() === Verified`. Organizer grants enforce
  this; artist grants continue to enforce KYC through the existing application flow
  until that flow migrates onto this layer.
- **`CapabilityService` is the only writer.** Models/controllers read via
  `User::hasCapability()` / the `capabilities()` relation.

## Endpoints

- `GET /api/capabilities` — current user's posture across all five capabilities
  (powers the frontend account-mode switcher).
- `POST /api/capabilities/organizer/apply` — self-service organizer onboarding
  (organization name, phone, experience). Previously impossible without an admin
  editing a JSON blob.
- `GET /api/admin/capabilities/pending`, `POST /api/admin/capabilities/{id}/review`
  — admin review queue (decision: grant|reject; grant is KYC-gated).

## Migration path

1. `php artisan capabilities:backfill` seeds grants from current reality: approved
   artists, active store owners, active onboarded promoter profiles, and legacy
   organizer JSON flags. Idempotent.
2. `User::isEventOrganizer()` reads grants first and falls back to the legacy JSON;
   the fallback (and `syncEventOrganizerProfile`) is removed once backfill has run
   everywhere.
3. Next consumers (tracked under Phases 3–4): artist application approval grants
   `Capability::Artist`; store activation grants `Capability::Seller`; promoter
   onboarding grants `Capability::Promoter`; V1 promotion routes swap their
   `role:artist` gate for the promoter capability; payout destinations attach to
   capability profiles alongside the unified payouts table.
