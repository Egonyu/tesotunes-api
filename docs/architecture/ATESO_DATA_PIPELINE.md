# Ateso Data Pipeline — TesoTunes as a Translation Corpus Engine

**Status:** Phase 9 design (build starts after production deploy + hardening).
**Goal:** feed the `ateso-nlp` model with high-quality, licensed, contemporary EN↔Ateso
sentence pairs, harvested from the TesoTunes community — extending the existing
`ateso-bible-corpus` beyond formal/archaic register into lyrical and conversational Ateso.

## Why TesoTunes is the right host

| Need | TesoTunes already has it |
|---|---|
| Contemporary Ateso text | Song lyrics (`songs.lyrics`, `primary_language`, `languages_sung`) |
| Motivated native speakers | Iteso listeners/artists who care about their music |
| Distribution to participants | Edula feed (activity spine + sponsored-slot injection) |
| Micro-payments | Credits → UGX wallet → mobile money, KYC-gated |
| Pay-on-verified-work pattern | Promotion escrow: submit → validate → settle |
| Reputation/tier machinery | Promoter tiers, capability grants |

The structural insight: **a translation contribution is the same shape as a promotion
deal** — work is submitted, held, peer-verified, then paid. The pipeline rides the
settlement rails rather than inventing a new reward system.

## Core principle

> **Gamify participation; pay only on accepted quality.**

Paid crowdsourcing attracts spam. Every mechanic below exists to make the *accepted*
corpus trustworthy, not to maximize raw submissions. A small clean corpus beats a large
poisoned one.

## Contribution surfaces (in rollout order)

1. **Lyrics translation** — "Help translate this song." Line-by-line EN↔Ateso on songs
   already on the platform. Intrinsically motivating; fans pick songs they love.
   Artist opt-in per song (rights stay clean); artists earn a small cut when their
   lyrics generate accepted pairs — incentive to opt in.
2. **Edula micro-tasks** — one-sentence cards injected into the feed (reusing the
   sponsored-slot injection mechanism, labeled "Earn"): *"How would you say this in
   Ateso?"* One tap to answer, one to skip.
3. **Daily challenge** — a themed prompt of the day (market talk, family, weather,
   proverbs) targeting registers and domains the corpus lacks. Curated from corpus-gap
   analysis.
4. **Validation tasks** — rate/fix/reject other people's pairs. Validators are the
   scaling bottleneck; validation pays slightly less per task but is faster, so
   throughput balances.

## Quality gate (the part that makes the data usable)

```
sentence pair task
   ├─ N independent translations (N=3 default, configurable)
   ├─ peer validation votes (agree / minor-fix / reject)
   ├─ agreement ≥ threshold → ACCEPTED → corpus + payouts settle
   ├─ disagreement → tie-break queue (trusted reviewers)
   └─ gold-standard salting: known-answer items (seeded from ateso-bible-corpus)
      mixed invisibly into every contributor's stream
```

- **Gold pass-rate drives reputation.** Fail golds → contributions down-weighted,
  pending payouts held, history re-reviewed. Pass consistently → trusted tier:
  higher per-task rate, tie-break rights, fewer redundant checks on your work.
- **No self-validation, no validating accounts you referred** (collusion guard —
  referral graph already exists).
- **Daily caps** per contributor (reuse the credits daily-limit pattern) to blunt
  farming and keep the reward pool predictable.
- **Orthography normalization:** Ateso spelling is not fully standardized. The pipeline
  stores the raw submission AND a normalized form (house style guide, versioned);
  validators flag spelling-only diffs as minor-fix, not reject.

## Reward economics

- Rewards are **credits, granted on acceptance**, recorded as `settlements`
  (`vertical: contributions`, `kind: translation_accepted`) — pending → cleared on
  acceptance → withdrawable via the existing KYC-gated payout flow.
- Funded from a **daily pool** (like `credits:distribute-listen-earn`): admins set
  the pool; per-pair value floats with volume so spend is capped, never open-ended.
- Leaderboards + badges (existing referral-leaderboard pattern) carry the
  non-monetary motivation; money stays small ("airtime money"), pride stays big.

## Data model (new module: `app/Modules/Contributions`, Store-template structure)

- `contribution_tasks` — uuid, type (translate|transcribe|validate), source morph
  (song lyric line, prompt, pair-under-review), source_lang, target_lang, register tag,
  gold flag (hidden), status, redundancy target.
- `contribution_submissions` — task FK, user FK, raw_text, normalized_text, status
  (submitted|accepted|rejected|superseded), agreement_score, settled flag.
- `contribution_validations` — submission FK, validator FK, verdict, suggested_fix.
- `corpus_pairs` — the export table: en_text, ateso_text, register, provenance
  (contributor ids, validation trail), license_version, quality_score, corpus_version.
- `contributor_profiles` — gold pass-rate, tier, totals (or fold into capability
  metadata: `Capability::Contributor`).

## Consent, licensing, provenance

- First contribution requires explicit acceptance of data terms: contributions are
  licensed for corpus use/publication (recommend CC-BY-SA or CC0 for the corpus
  release — decide before launch), attribution optional and pseudonymous.
- Artists opt in per song before lyrics enter the task pool.
- Exports are versioned (`ateso-corpus-v2.jsonl`), carry per-pair provenance and
  quality scores, and never include PII. Export command: `corpus:export {--version=}`.

## Build order

| Step | Scope |
|---|---|
| 9.1 | Module skeleton + schema (ground-up domain migration) + consent flow |
| 9.2 | Lyrics translation surface (song page + artist opt-in) + submission flow |
| 9.3 | Validation tasks + agreement scoring + gold salting + reputation |
| 9.4 | Settlement integration (accept → settle) + daily pool + caps |
| 9.5 | Edula task cards + daily challenge |
| 9.6 | Admin console (pool, thresholds, review queue, corpus health) + `corpus:export` |

## Open decisions (need your call before 9.1)

1. Corpus license: CC-BY-SA vs CC0 vs custom.
2. Reward sizing: suggested start UGX 100–300 per accepted pair equivalent, validation
   ~40% of that — tune against the daily pool.
3. House orthography reference: which spelling convention the style guide follows.
4. Whether transcription (audio → Ateso text) is in scope for v1 — valuable for ASR
   later, but doubles the review burden.
