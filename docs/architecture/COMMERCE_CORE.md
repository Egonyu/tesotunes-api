# Commerce Core — Unified Settlement Ledger

**Status:** Phase 2 of the platform rebuild. This document is the source of truth for
how seller-side money works across every vertical.

## Problem

TesoTunes has one identity system and three commerce systems. Money settles differently
per vertical:

| Vertical | Today |
|---|---|
| Music | `ArtistRevenue` → `pending_earnings` → KYC-gated withdrawal. Real settlement. |
| Store | `platform_fee_ugx/credits` computed per order; seller proceeds never reach a withdrawable balance. |
| Events | `EventPayoutLedgerService` produces *estimates* for analytics; no actual settlement. |
| Promotions | V2 awards a deal and stops — no escrow, no payment, no settlement. |

Every new selling capability (merchant, organizer, promoter, label) would otherwise
invent a fourth/fifth settlement model. The fix is one ledger.

## Design

**One rule: every shilling or credit owed to a beneficiary becomes a `settlements` row.**

```
checkout (existing, per vertical)
   └─ payment confirmed
        └─ SettlementService::record(beneficiary, source, vertical, kind, amounts)
             └─ settlements row: PENDING (hold window for disputes/refunds)
                  └─ commerce:clear-due-settlements (scheduled) → CLEARED
                       └─ payout executes → PAID_OUT      (or dispute → REVERSED)
```

### The `settlements` table

- `beneficiary_user_id` — always a **user**, never a role-specific entity. Artists,
  merchants, organizers, and promoters are all users with capability grants (Phase 3);
  the ledger doesn't care which hat they wore when they earned.
- `source` (morph) — the transaction that produced the money: a store order, an event
  attendee/ticket sale, a promotion order item, an artist revenue event. Unique per
  `(source, beneficiary, kind)` so re-processing a webhook can never double-settle.
- `vertical` + `kind` — reporting dimensions (`store/sale`, `events/ticket_sale`,
  `music/stream`, `promotions/promo_service`, …).
- Dual-currency amounts — `gross/fee/net` in both UGX (decimal) and credits (int),
  matching the platform's hybrid checkout. `net = gross − fee` is enforced by the
  service, never trusted from callers.
- Lifecycle — `pending → cleared → paid_out`, with `reversed` reachable from
  pending/cleared (refunds, disputes). Timestamps for every transition.
- `payout` (nullable morph) — links cleared rows to the payout that disbursed them
  (`artist_payouts` today; the unified payouts table arrives with Phase 3 capability
  profiles, and the morph absorbs that change without schema surgery).

### Clearance holds

`config/commerce.php` defines per-vertical hold windows (default 3 days; store sales
hold for the dispute window, event ticket sales hold until after the event via
caller-supplied `hold_until`). The scheduled `commerce:clear-due-settlements` command
promotes due rows to `cleared`. Cleared (not pending) balance is what payout flows are
allowed to disburse — same KYC gate as today (`kyc:withdrawal`).

### Producers (wired incrementally)

1. **Store (this phase):** `Store\PaymentService::confirmPayment()` records a settlement
   for the store owner inside the same DB transaction that marks the order paid.
2. **Events (next):** ticket purchase completion records a settlement for the event
   owner with `hold_until = event end`; replaces the estimate-only ledger service as
   the source of truth (analytics keep reading their own service).
3. **Promotions (Phase 4 bridge):** opportunity award / package purchase creates an
   escrow order; *verification* (not payment) records the promoter's settlement —
   funds stay platform-held until proof is accepted, `reverse()` on upheld disputes.
4. **Music (migration, last):** `ArtistRevenue` keeps operating (564 live rows) and
   becomes a settlement producer; its pending/confirmed/paid lifecycle maps 1:1 onto
   the ledger. No big-bang cutover — dual-write first, then reads move over.

### What this deliberately does NOT do (yet)

- No checkout rewrite — each vertical keeps its checkout; only settlement converges.
- No unified payouts table — Phase 3 (payout destinations belong to capability profiles).
- No ledger-based accounting views/admin UI — after producers are live.

## Invariants (enforced in service + tests)

1. `net_ugx = gross_ugx − fee_ugx` and `net_credits = gross_credits − fee_credits`.
2. A `(source, beneficiary, kind)` triple settles at most once.
3. Only `cleared` rows can be marked `paid_out`; only `pending/cleared` can be reversed.
4. Settlement writes ride the same DB transaction as the payment confirmation that
   produced them — a paid order without a settlement row cannot exist.
