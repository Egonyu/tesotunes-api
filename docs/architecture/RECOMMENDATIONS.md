# Recommendations — Homepage & Discovery

**Status:** Phase 7 of the platform rebuild. Benchmark: Spotify's home — shelves that
feel alive, differ per listener, and change day to day.

## Architecture

`HomepageService` composes the home response as an ordered list of **shelf modules**
(hero, quick picks, recently played, made-for-you, because-you-listened,
recommended-today, new-from-followed, editorial, ecosystem). Each shelf builder is
independent: it returns items or null, so the page degrades gracefully as signals
thin out. `GET /api/homepage?mode=all|music|radio|uganda|fresh` applies a mode lens
to every shelf. Guest responses are cached 5 minutes; authenticated ones never.

## Audience layers

| Layer | Signals | Shelves |
|---|---|---|
| Guest / cold start | windowed trending, editorial flags, regional heuristics, freshness | hero, quick picks, recommended-today, editorial |
| Light listener | + own recent plays, likes → top-3 genre affinity | + made-for-you (genre lane) |
| Engaged listener | + follow graph, repeat-artist signal, co-listen graph | + because-you-listened, new-from-followed |

Every personalized shelf has a guest-safe fallback, so a brand-new account always
gets a full page (cold-start rule: no empty shelves, no "not enough data" states).

## Ranking signals (current heuristics)

1. **Windowed trending** (`trendingSongs`): engagement over the last 14 days from
   `play_histories` — completed plays weigh 1.0, partial 0.5, skips excluded —
   backfilled by all-time `play_count` when sparse. This replaced all-time play
   counts, which made the homepage static forever.
2. **Candidate-pool scoring** (`recommended-today`): pool = newest 40 ∪ most-played
   40 ∪ genre-affinity 30, scored on plays, likes, editorial boost, freshness,
   genre affinity (+16), follow graph (+20), already-heard penalty (−18), and a
   **deterministic daily rotation jitter** (0–8 pts, seeded by user-id + date) so
   the shelf reshuffles overnight even when the catalog is static. (The previous
   implementation scored an unordered `limit(40)` — i.e. the oldest rows only.)
3. **Co-listen collaborative layer** (`because-you-listened`): songs by *other*
   artists that the repeat-artist's listeners also played in the last 90 days,
   ranked by distinct co-listener count, labeled "Listeners also play"; the
   artist's own top tracks fill remaining slots. This is the first cross-user
   learning signal on the platform; it works at current scale (~550 play rows)
   with two indexed queries and grows naturally with traffic.
4. **Regional tilt**: Uganda/East-Africa heuristics (artist country/city, genre
   slugs) boost the `uganda` mode and the default ranking.

## Quality cues already captured for the next iteration

`play_histories` stores `completed`, `skipped`, `completion_percentage`, and
`source` — enough to evolve scoring toward completion-weighted affinity and
skip-as-negative-feedback without schema changes.

## Evolution path (in order, each step measurable before the next)

1. **Instrument**: log shelf impressions/clicks (feed_analytics pattern) → CTR per
   shelf per audience layer becomes the metric all changes answer to.
2. **Completion-weighted affinity**: genre/artist affinity scored by completion
   percentage rather than play presence; skips subtract.
3. **Artist-level co-listen matrix**: precompute artist↔artist co-listen scores
   nightly (scheduled command, reuses UpdateArtistCachedStats cadence) once
   play_histories outgrows on-request queries (~100k rows).
4. **Embedding/model ranking**: only when data volume justifies it — the shelf
   architecture stays identical; only the scorer changes.

## Invariants (pinned by HomepageRecommendationsTest)

- Recently-played songs outrank stale all-time hits in trending.
- A new high-engagement song must be able to surface in recommended-today
  regardless of catalog age (candidate-pool regression).
- Co-listened songs from other artists appear in because-you-listened with their
  own label.
- A guest with zero signals still gets a populated homepage.
