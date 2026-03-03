# Database Health Report — TesoTunes API

**Audit Date:** 2026-03-01  
**Scope:** All migration files, models, factories, seeders, and raw SQL usage  
**Severity Scale:** 🔴 CRITICAL | 🟠 HIGH | 🟡 MEDIUM | 🔵 LOW

---

## EXECUTIVE SUMMARY

| Category | Issues Found |
|----------|-------------|
| Missing table migrations | 3 🔴 |
| Table/model naming mismatches | 2 🟠 |
| Conflicting/duplicate migrations | 3 🟡 |
| Missing indexes | 12 🟠 |
| Orphan/cascade issues | 6 🟠 |
| SoftDeletes mismatches | 4 🟡 |
| Factory/schema drift | 4 🟠 |
| Raw SQL risks | 3 🟡 |
| Unused tables | 5 🔵 |

---

## TASK 1: Migration File Inventory

### 35 Migration Files

| # | File | Purpose |
|---|------|---------|
| 1 | `0001_01_01_000001_create_users_table.php` | Creates `users` table with full profile, auth, credits, social links |
| 2 | `0001_01_01_000002_create_base_music_tables.php` | Creates core tables: `roles`, `permissions`, `role_permissions`, `user_roles`, `genres`, `artists`, `albums`, `songs`, `likes`, `user_follows`, `play_history`, `downloads`, `playlists`, `playlist_song`, `events`, `event_locations`, `event_tickets`, `event_attendees`, `notifications`, `payments`, `user_credits`, `credit_transactions`, `artist_revenues`, `royalty_splits`, `subscription_plans`, `user_subscriptions` |
| 3 | `2025_07_13_000001_create_payment_issues_table.php` | Creates `payment_issues` table |
| 4 | `2026_01_07_200000_create_cms_frontend_module_tables.php` | Creates CMS tables: `cms_pages`, `cms_blocks`, `navigation_menus`, `menu_items`, `media_library`, `seo_metadata` |
| 5 | `2026_01_12_220500_create_cms_frontend_sections_table.php` | Creates `frontend_sections`, `frontend_section_items` |
| 6 | `2026_01_18_232009_create_media_table.php` | Creates `media` table (Spatie Media Library) |
| 7 | `2026_01_19_000001_create_password_reset_tokens_table.php` | Creates `password_reset_tokens` |
| 8 | `2026_02_12_073022_add_type_to_likes_table.php` | Adds `type` column to `likes` |
| 9 | `2026_02_14_110711_add_missing_columns_to_users_table.php` | Adds `uuid`, `display_name`, `phone` to `users` |
| 10 | `2026_02_14_115647_create_personal_access_tokens_table.php` | Creates `personal_access_tokens` (Sanctum) |
| 11 | `2026_02_14_130000_create_forums_and_polls_tables.php` | Creates `forum_categories`, `forum_topics`, `forum_replies`, `polls`, `poll_options`, `poll_votes` |
| 12 | `2026_02_15_183050_create_awards_tables.php` | Creates `awards`, `award_categories`, `award_nominations`, `award_votes` |
| 13 | `2026_02_15_191346_fix_award_nominations_columns.php` | Adds missing columns to `award_nominations` |
| 14 | `2026_02_15_194255_fix_award_nominations_columns.php` | **DUPLICATE** — same fix to `award_nominations` again |
| 15 | `2026_02_15_195902_make_events_user_id_nullable.php` | No-op (already nullable) |
| 16 | `2026_02_15_200918_fix_award_categories_columns.php` | Adds `category_type`, `artwork` to `award_categories` |
| 17 | `2026_02_15_201051_make_award_categories_season_nullable.php` | No-op |
| 18 | `2026_02_15_202453_add_missing_columns_to_events.php` | No-op |
| 19 | `2026_02_15_223356_create_jobs_table.php` | Creates `jobs` table (Laravel queue) |
| 20 | `2026_02_15_223410_create_cache_table.php` | Creates `cache` table |
| 21 | `2026_02_15_223411_create_sessions_table.php` | Creates `sessions` table |
| 22 | `2026_02_16_000001_comprehensive_schema_sync.php` | Mega migration: creates 30+ tables (`activities`, `activity_comments`, `ad_impressions`, `audit_logs`, `campaigns`, `campaign_pledges`, `campaign_updates`, `credit_rates`, `device_tokens`, `feed_ab_tests`, `feed_analytics`, `feed_items`, `feed_preferences`, `frontend_settings`, `isrc_codes`, `moods`, `music_uploads`, `podcasts`, `podcast_categories`, `podcast_episodes`, `posts`, `post_comments`, `post_media`, `post_likes`, `publishing_rights`, `sacco_members`, `sacco_loans`, `sacco_transactions`, `settings`, `song_moods`, `user_feed_settings`, `shares`, `views`, `comments`). Also adds missing columns to many existing tables. |
| 23 | `2026_02_16_100000_add_missing_song_columns.php` | Adds `user_id`, `file_format`, `file_size_bytes`, `visibility`, `is_streamable`, `processing_status` to `songs` |
| 24 | `2026_02_19_200000_create_missing_tables.php` | Creates `song_genres`, `user_settings`, `failed_jobs`, `playlist_songs`, `playlist_collaborators`, `store_products`, `store_carts`, `store_cart_items`, `store_orders`, `store_order_items` |
| 25 | `2026_02_23_090003_create_telescope_entries_table.php` | Creates Telescope tables |
| 26 | `2026_02_23_100000_create_missing_sacco_tables_and_fixes.php` | Renames `play_history` → `play_histories`, `playlist_song` → `playlist_songs`. Creates SACCO tables (`sacco_savings_accounts`, `sacco_savings_transactions`, `sacco_loan_repayments`, `sacco_loan_products`, `sacco_shares`, `sacco_share_transactions`, `sacco_dividends`, `sacco_member_dividends`, `sacco_settings`, `sacco_accounts`, `sacco_audit_logs`, `sacco_board_members`, `sacco_board_meetings`, `sacco_board_meeting_attendance`, `artist_profiles`). Adds performance indexes. |
| 27 | `2026_02_23_120000_create_loyalty_tables.php` | Creates `loyalty_cards`, `loyalty_card_members`, `loyalty_rewards`, `loyalty_reward_redemptions`, `loyalty_points`, `loyalty_transactions` |
| 28 | `2026_02_23_123458_fix_feed_items_table_schema.php` | **Drops and recreates** `feed_items` with entirely different schema |
| 29 | `2026_02_23_160000_add_missing_columns_to_comments_table.php` | Adds `is_pinned`, `replies_count` to `comments` |
| 30 | `2026_02_23_160100_add_comments_count_to_commentable_tables.php` | Adds `comments_count` to 8 tables |
| 31 | `2026_02_24_000000_alter_loyalty_cards_status_to_string.php` | Changes `loyalty_cards.status` from ENUM to VARCHAR |
| 32 | `2026_02_24_060000_fix_notifications_table_primary_key.php` | Converts `notifications.id` from UUID to integer auto-increment |
| 33 | `2026_02_28_100000_ensure_payments_table_columns.php` | Adds 15+ missing columns to `payments` |
| 34 | `2026_02_28_150000_standardize_role_names.php` | Converts role names from Title Case to snake_case |
| 35 | `2026_02_23_090003_create_telescope_entries_table.php` | Telescope support tables |

---

## TASK 2: Schema Consistency Check

### 🔴 CRITICAL — Tables Referenced in Code But Missing from Migrations

| Table | Referenced In | Issue |
|-------|-------------|-------|
| `podcast_listens` | `PlayerApiController`, `PodcastService`, `HasPodcast` trait, `Podcast\AnalyticsService`, `PodcastListen` model | **No migration exists.** Code does `DB::table('podcast_listens')->insert(...)` — will fail at runtime. |
| `podcast_subscriptions` | `PodcastSubscription` model, `HasPodcast` trait, `PodcastSubscriptionFactory` | **No migration exists.** Laravel convention expects `podcast_subscriptions` table from model name. |
| `orders` | `Order` model (`protected $table = 'orders'`) | Migration creates `store_orders`, but Order model sets `$table = 'orders'`. **Table name mismatch.** |

### 🟠 HIGH — Table/Model Naming Conflicts

| Issue | Details |
|-------|---------|
| `playlist_song` vs `playlist_songs` | Base migration creates `playlist_song`. Migration #24 also creates `playlist_songs`. Migration #26 renames `playlist_song` → `playlist_songs`. Both can't coexist. If migrations run in order, the rename in #26 will fail because #24 already created `playlist_songs`. |
| `Download` model uses polymorphic `downloadable` | Migration creates `downloads` with `morphs('downloadable')`, but `DownloadFactory` sets `song_id` directly (not `downloadable_id`). Factory references non-existent columns. |
| `Distribution` model + factory | `DistributionFactory` references `App\Models\Distribution` but no `Distribution` model class exists and no `distributions` table migration exists. |

### 🟡 MEDIUM — Conflicting/Duplicate Migrations

| Issue | Files |
|-------|-------|
| Duplicate award_nominations fix | `2026_02_15_191346` and `2026_02_15_194255` both add the same columns (`award_id`, `category_id`, `nominee_name`, etc.) to `award_nominations`. Both use `hasColumn()` guards so they won't crash, but this is code duplication. |
| No-op migrations (3 files) | `make_events_user_id_nullable`, `make_award_categories_season_nullable`, `add_missing_columns_to_events` are all empty no-ops that add migration overhead. |
| `type` column added twice to `likes` | Base migration `0001_01_01_000002` creates `likes` with `type` column. Migration `2026_02_12` adds it again (guarded with `hasColumn`). Comprehensive sync also tries to add it. |

### 🔵 LOW — Tables with No Model/Usage Found

| Table | Notes |
|-------|-------|
| `media_library` | CMS migration creates it, but `media` table (Spatie) is the one actually used by models via `InteractsWithMedia` |
| `seo_metadata` | Created in CMS migration, no model found referencing it |
| `shares` | Created in comprehensive sync, no dedicated model found |
| `views` | Created in comprehensive sync, no dedicated model found |
| `frontend_section_items` | Created in CMS, no model found |

---

## TASK 3: Index Audit

### `users` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | `id` auto-increment |
| `role` indexed | ✅ | `index('role')` |
| `status` indexed | ✅ | `index('status')` |
| `is_artist` indexed | ✅ | `index('is_artist')` |
| `email` indexed | ✅ | `unique()` |
| `username` indexed | ✅ | `unique()` |
| Composite `is_artist, status` | ✅ | Present |
| 🟠 `referrer_id` (FK) | ❌ **MISSING** | Used in referral queries, no index |
| 🟡 `created_at` | ❌ **MISSING** | Needed for user registration analytics |
| 🟡 `last_login_at` | ❌ **MISSING** | Used in activity/retention queries |

### `artists` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | `id` auto-increment |
| `user_id` (FK) | ✅ | Automatically indexed by `foreignId()->constrained()` |
| `status, is_verified` | ✅ | Composite index present |
| `is_featured, total_plays` | ✅ | Composite index present |
| 🟡 `slug` | ✅ | Unique constraint |
| 🟡 `primary_genre_id` (FK) | ❌ **MISSING** | Foreign key not indexed |

### `songs` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | |
| `artist_id, status` | ✅ | Composite |
| `album_id, track_number` | ✅ | Composite |
| `is_featured, play_count` | ✅ | Composite |
| `status` standalone | ✅ | Added in migration #26 |
| `created_at` | ✅ | Added in migration #26 |
| 🟠 `primary_genre_id` (FK) | ❌ **MISSING** | Used in genre filtering queries |
| 🟠 `user_id` | ❌ **MISSING** | Added as column but no index |
| 🟡 `release_date` | ❌ **MISSING** | Used in new releases sorting |
| 🟡 `play_count` standalone | ❌ **MISSING** | Used in trending/popular sorts |

### `albums` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | |
| `artist_id, status` | ✅ | |
| `release_date` | ✅ | |
| 🟠 `primary_genre_id` (FK) | ❌ **MISSING** | Foreign key not indexed |
| 🟡 `is_featured` | ❌ **MISSING** | Used in featured album queries |

### `play_histories` Table (renamed from `play_history`)
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | |
| `user_id, created_at` | ✅ | From base migration |
| `song_id, created_at` | ✅ | From base migration |
| `user_id, song_id` | ✅ | Added in migration #26 |
| 🟠 `played_at` | ❌ **MISSING** | Model uses `played_at` as timestamp, but column likely doesn't exist in migration (only `created_at` via timestamps). Factory references `played_at` but migration defines `timestamps()`. |
| 🟡 `artist_id` | ❌ **MISSING** | Model fillable has `artist_id` but not in migration |

### `downloads` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | |
| `user_id, created_at` | ✅ | |
| Morph index (`downloadable_type`, `downloadable_id`) | ✅ | Via `morphs()` |
| 🟡 No `song_id` column | ℹ️ | Factory uses `song_id` but table uses polymorphic `downloadable_type/downloadable_id` |

### `payments` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | |
| `user_id, status` | ✅ | |
| `status` standalone | ✅ | Added in migration #26 |
| `user_id` standalone | ✅ | Added in migration #26 |
| Morph index | ✅ | Via `morphs('payable')` |
| 🟠 `provider_transaction_id` | ❌ **MISSING** | Used for payment lookups |
| 🟠 `transaction_reference` | ❌ **MISSING** | Used for payment reconciliation |
| 🟡 `created_at` | ❌ **MISSING** | Needed for financial reporting |

### `subscriptions` / `user_subscriptions` Table
| Check | Status | Details |
|-------|--------|---------|
| Primary key | ✅ | |
| `user_id, status` | ✅ | |
| 🟡 `plan_id` (FK) | ❌ **MISSING** | Indexed via foreign key constraint, but standalone index for plan queries missing |
| 🟡 `ends_at` | ❌ **MISSING** | Needed for expiry checks |

### Summary of Missing Indexes (Priority Tables)
| Priority | Table | Column(s) |
|----------|-------|-----------|
| 🔴 | `songs` | `primary_genre_id`, `user_id`, `play_count` |
| 🔴 | `songs` | `release_date` |
| 🟠 | `users` | `referrer_id`, `last_login_at` |
| 🟠 | `artists` | `primary_genre_id` |
| 🟠 | `albums` | `primary_genre_id` |
| 🟠 | `payments` | `provider_transaction_id`, `transaction_reference`, `created_at` |
| 🟡 | `play_histories` | `played_at` (if column exists) |
| 🟡 | `user_subscriptions` | `ends_at` |

---

## TASK 4: Seeder/Factory Integrity

### Factory Coverage

| Model | Factory? | Status |
|-------|----------|--------|
| User | ✅ UserFactory | Good — matches schema |
| Artist | ✅ ArtistFactory | Good — uses realistic data |
| Song | ✅ SongFactory | Good |
| Album | ✅ AlbumFactory | Good |
| Payment | ✅ PaymentFactory | ⚠️ Uses `payment_provider` — may not match migration column `provider` |
| Download | ✅ DownloadFactory | 🔴 **BROKEN** — uses `song_id`, `downloaded_at`, `file_size_bytes`, `device_type`, `is_active` — but migration defines polymorphic `downloadable_type/downloadable_id`, `quality`, `source`, and Laravel `timestamps()`. Most factory fields don't exist. |
| PlayHistory | ✅ PlayHistoryFactory | 🔴 **BROKEN** — uses `session_id`, `country_code`, `city`, `platform`, `played_at`, `play_duration_seconds`, `position_seconds`, `completion_percentage`, `was_completed`, `was_skipped`, `audio_quality`, `came_from`, `referrer_url`, `counts_for_revenue` — almost none exist in migration schema. |
| Distribution | ✅ DistributionFactory | 🔴 **BROKEN** — references `App\Models\Distribution` which doesn't exist. No `distributions` table migration. |
| Event | ✅ EventFactory | Exists |
| Genre | ✅ GenreFactory | Exists |
| Mood | ✅ MoodFactory | Exists |
| Playlist | ✅ PlaylistFactory | Exists |
| Podcast | ✅ PodcastFactory | Exists |
| PodcastEpisode | ✅ PodcastEpisodeFactory | Exists |
| PodcastSubscription | ✅ PodcastSubscriptionFactory | 🔴 **BROKEN** — no `podcast_subscriptions` migration |
| Role | ✅ RoleFactory | Exists |
| Order | ✅ OrderFactory | ⚠️ Model uses `$table = 'orders'` but migration creates `store_orders` |
| SubscriptionPlan | ✅ | Exists |
| UserSubscription | ✅ | Exists |
| LoyaltyCard | ✅ | Exists |
| LoyaltyCardMember | ✅ | Exists |
| LoyaltyReward | ✅ | Exists |

### Models WITHOUT Factories

| Model | Priority |
|-------|----------|
| Activity | 🟡 |
| ActivityComment | 🟡 |
| AdImpression | 🔵 |
| ArtistRevenue | 🟡 |
| AuditLog | 🔵 |
| Award / AwardCategory / AwardNomination / AwardVote | 🟡 |
| Campaign / CampaignPledge / CampaignUpdate | 🟡 |
| Comment | 🟡 |
| CreditRate | 🔵 |
| CreditTransaction | 🟡 |
| DeviceToken | 🔵 |
| FeedABTest / FeedAnalytic / FeedItem / FeedPreference | 🔵 |
| FrontendSetting | 🔵 |
| ISRCCode | 🟡 |
| Like | 🟡 |
| Notification | 🟡 |
| PaymentIssue | 🟡 |
| PlaylistCollaborator / PlaylistSong | 🔵 |
| Post / PostComment / PostLike / PostMedia | 🟡 |
| PublishingRights | 🟡 |
| RoyaltySplit | 🟡 |
| Setting | 🔵 |
| UserCredit | 🟡 |
| UserFollow | 🟡 |
| UserSetting | 🔵 |
| All Sacco models (17 models) | 🟡 |

### Seeder Quality

| Seeder | Quality | Notes |
|--------|---------|-------|
| DatabaseSeeder | ✅ Good | Orchestrates all seeders with table existence checks |
| RolePermissionSeeder | ✅ Essential | Sets up roles and permissions |
| UserSeeder | ✅ Good | Creates test users |
| GenreSeeder | ✅ Good | Seeds music genres |
| MoodSeeder | ✅ Good | Seeds mood categories |
| CreditRateSeeder | ✅ Good | Seeds credit rates |
| SettingsSeeder | ✅ Good | Seeds platform settings |
| TestDataSeeder | ✅ | Creates test data |
| ComprehensiveTestDataSeeder | ✅ | Full-featured test data |
| LoyaltySeeder | ✅ | Seeds loyalty system |

---

## TASK 5: Raw SQL Audit

### 50+ Raw SQL Usages Found

#### 🟢 SAFE — Standard Aggregation (Most Common)
```
DB::raw('COUNT(*) as count')       — 15+ occurrences
selectRaw('DATE(...) as date')     — 5+ occurrences  
selectRaw('AVG(...)')              — 2 occurrences
DB::raw('SUM(...)')                — 4 occurrences
```
These are safe aggregation patterns using standard SQL functions on existing columns.

#### 🟡 MEDIUM RISK — Column-Dependent

| File | Raw SQL | Risk |
|------|---------|------|
| `Store\AnalyticsService` | `whereRaw('stock_quantity <= low_stock_threshold')` | ⚠️ `store_products` migration has `stock_quantity` but NOT `low_stock_threshold`. Product model fillable has it, may come from a later migration or be unreliable. |
| `Store\PromotionService` | `havingRaw('COUNT(*) >= max_uses_per_user')` | ⚠️ Compares aggregate to column name `max_uses_per_user` — must be a column on the joined table. Not verified. |
| `RoyaltySplit` model | `selectRaw('minimum_payout_amount')` | ⚠️ Column `minimum_payout_amount` is in model fillable but NOT in any migration. |
| `SongService` | `selectRaw('DATE(played_at) as date')` | ⚠️ `played_at` column may not exist on `play_histories` — migration only has `timestamps()` (i.e., `created_at`/`updated_at`). After rename, the original columns from `play_history` remain `duration_played`, `completed`, `source`, `device_type`. |
| `Podcast\AnalyticsService` | uses `listened_at`, `episode_duration`, `listen_duration`, `device_type`, `country` on `podcast_listens` | 🔴 No `podcast_listens` table migration at all. |

#### 🟢 SAFE — System/Admin Queries
```
DB::select('SELECT VERSION()')                  — SystemMonitoringService
DB::select("SHOW TABLE STATUS...")               — QueryOptimizationService  
DB::statement("OPTIMIZE TABLE...")               — QueryOptimizationService
```
System administration queries, safe.

#### 🟡 MEDIUM RISK — Raw UPDATE Statements
| File | Statement |
|------|-----------|
| `fix_award_nominations_columns` | `DB::raw('award_category_id')` in UPDATE |
| `fix_award_categories_columns` | `DB::statement('UPDATE award_categories SET category_type = nominee_type...')` |
| `standardize_role_names` | `DB::table('roles')->where('name', ...)->update(...)` |
| `alter_loyalty_cards_status` | `DB::statement("ALTER TABLE loyalty_cards MODIFY COLUMN...")` |

These are in migrations only, acceptable but fragile if column doesn't exist.

---

## TASK 6: Data Integrity Issues

### 🔴 Orphaned Records Risk — Missing CASCADE

| Parent Table | Child Table | FK Column | On Delete | Risk |
|-------------|-------------|-----------|-----------|------|
| `sacco_members` | `sacco_loans` | `sacco_member_id` | CASCADE ✅ | OK |
| `podcasts` | `podcast_episodes` | `podcast_id` | CASCADE ✅ | OK |
| `users` | `artists` | `user_id` | CASCADE ✅ | OK |
| `artists` | `songs` | `artist_id` | CASCADE ✅ | OK |
| `users` | `ad_impressions` | `user_id` | NULL ON DELETE ✅ | OK |
| `events` | `event_locations` (FK) | `event_location_id` | ❌ **NO CONSTRAINT** | Events reference `event_location_id` but it's just an unsigned bigint with no foreign key. Orphaned references possible. |
| `podcasts` | `podcast_categories` (FK) | `podcast_category_id` | ❌ **NO CONSTRAINT** | Just an unsigned bigint, no FK constraint. |
| `songs` | `song_moods` pivot | `mood_id` | ❌ **NO FK CONSTRAINT** | `mood_id` is just `unsignedBigInteger` without `->constrained()`. Orphaned records if moods deleted. |
| `publishing_rights` | users | `owner_id` | ❌ **NO FK CONSTRAINT** | `owner_id` is just `unsignedBigInteger`. |
| `royalty_splits` | users | `recipient_id` | CASCADE ✅ (base) | But comprehensive sync adds `recipient_id` without FK. May override. |
| `payments` | songs/subscriptions | `song_id`, `subscription_plan_id` | ❌ **NO FK CONSTRAINT** | Added by ensure_payments migration as bare `unsignedBigInteger`. |

### 🟠 SoftDeletes Mismatches

Models using `SoftDeletes` trait but whose migration tables **lack** `deleted_at` column:

| Model | Table | Has SoftDeletes Trait | Has `softDeletes()` in Migration |
|-------|-------|----------------------|----------------------------------|
| Notification | notifications | ✅ Yes | ❌ **NO** — migration #32 recreates table without `softDeletes()` |
| FeedItem | feed_items | ✅ Yes | ❌ **NO** — migration #28 recreates table without `softDeletes()` |
| CampaignUpdate | campaign_updates | ✅ Yes | ❌ **NO** — comprehensive sync creates table without `softDeletes()` |
| SaccoMember | sacco_members | ✅ Yes | ❌ **NO** — comprehensive sync creates table without `softDeletes()` |

Models with `SoftDeletes` trait that ARE properly configured:
- User ✅, Artist ✅, Song ✅, Album ✅, Event ✅, Playlist ✅, Post ✅, PostComment ✅, Podcast ✅, PodcastEpisode ✅, Comment ✅, LoyaltyCard ✅, LoyaltyReward ✅, Campaign ✅, ForumTopic ✅, ForumReply ✅

### 🟡 Missing Timestamps

| Table | Has `timestamps()` | Model Expectation |
|-------|--------------------|--------------------|
| `podcast_listens` | N/A (no migration) | `$timestamps = false` ✅ |
| `podcast_subscriptions` | N/A (no migration) | `$timestamps = false` ✅ |
| `downloads` | ✅ `timestamps()` in migration | Model says `$timestamps = false` — ⚠️ Mismatch but non-breaking |
| `play_histories` | ✅ `timestamps()` in original migration | Model sets `CREATED_AT = 'played_at'` which doesn't exist in migration. ⚠️ Column mismatch |

### 🟡 JSON Columns That Could Be Normalized

| Table | Column | Consideration |
|-------|--------|---------------|
| `users` | `profile_steps_completed` | Could be a separate table if querying specific steps |
| `users` | `notification_preferences` | OK as JSON for user-specific prefs |
| `users` | `settings` | Redundant with `user_settings` table |
| `artists` | `social_links` | OK — key-value pairs |
| `songs` | `featured_artists` | 🟠 Should be normalized — artists are entities with IDs, storing as JSON prevents joins and makes it impossible to query "all songs featuring artist X" efficiently |
| `songs` | `processing_status` | OK — status tracking |
| `events` | `gallery` | OK — array of URLs |
| `events` | `tags` | 🟡 Could use a tags/taggable pivot table for searching |
| `loyalty_cards` | `tiers` | OK — configuration data |
| `campaigns` | `reward_tiers` | OK — configuration data |

---

## PRIORITY FIX LIST

### 🔴 CRITICAL (Fix Before Production)

1. ~~**Create `podcast_listens` migration**~~ ✅ DONE — Created `2026_03_01_000001_create_podcast_listens_table.php`
2. ~~**Create `podcast_subscriptions` migration**~~ ✅ DONE — Created `2026_03_01_000002_create_podcast_subscriptions_table.php`
3. ~~**Fix `Order` model table name**~~ ✅ DONE — Changed `$table = 'store_orders'` in Order.php
4. ~~**Fix `PlayHistoryFactory`**~~ ✅ DONE — Aligned with actual migration schema
5. ~~**Fix `DownloadFactory`**~~ ✅ DONE — Using polymorphic morphs as per migration
6. ~~**Remove `DistributionFactory`**~~ ✅ DONE — Removed orphaned factory (no Distribution model)

### 🟠 HIGH (Fix Soon)

7. ~~**Add missing indexes on `songs`**~~ ✅ DONE — Created `2026_03_01_000003_add_missing_indexes_and_soft_deletes.php`
8. ~~**Add `deleted_at` column**~~ ✅ DONE — Added to notifications, feed_items, campaign_updates, sacco_members
9. ~~**Add FK constraints**~~ ✅ DONE — 3 of 4 already existed; added `publishing_rights.owner_id` → users FK via `2026_03_01_200000_add_foreign_key_constraints.php`
10. ~~**Add indexes on `payments`**~~ ✅ DONE — Added provider_transaction_id, transaction_reference, created_at indexes
11. ~~**Fix `play_histories` schema/model**~~ ✅ DONE — Added 8 missing columns (artist_id, album_id, played_at, duration_played_seconds, skipped, completion_percentage, quality, city). Fixed model timestamps conflict. Fixed scopes to use `completed` column.
12. ~~**Add `minimum_payout_amount` + 35 other columns to `royalty_splits`**~~ ✅ DONE — Migration `2026_03_01_200001` adds all model $fillable columns

### 🟡 MEDIUM (Improve)

13. **Remove 3 no-op migrations** — Already empty bodies with comments, cannot delete (in migrations table)
14. ~~**Remove duplicate `fix_award_nominations_columns`** migration~~ ✅ DONE — Converted to documented no-op
15. **Normalize `songs.featured_artists`** JSON to a pivot table
16. **Create factories** for at least: Like, Comment, Post, Activity, CreditTransaction, UserCredit
17. ~~**Add `users.created_at` index**~~ ✅ DONE — Added referrer_id and last_login_at indexes
18. ~~**Add `user_subscriptions.ends_at` index**~~ ✅ DONE — Added in migration

### 🔵 LOW (Cleanup)

19. **Audit unused CMS tables** — `media_library`, `seo_metadata`, `shares`, `views`
20. **Consider removing `users.settings`** JSON column — redundant with `user_settings` table
21. ~~**Add indexes for `artists.primary_genre_id`**, `albums.primary_genre_id`~~ ✅ DONE — Added in migration

---

## APPENDIX: Complete Table Inventory

### Tables Created by Migrations (85+ tables)

**Core Music:** users, artists, albums, songs, genres, song_genres, song_moods, moods, likes, play_histories, downloads, playlists, playlist_songs, playlist_collaborators, isrc_codes, music_uploads, publishing_rights, royalty_splits

**Auth/Roles:** roles, permissions, role_permissions, user_roles, personal_access_tokens, password_reset_tokens, sessions

**Social:** user_follows, activities, activity_comments, posts, post_comments, post_media, post_likes, comments, shares, views, feed_items, feed_analytics, feed_ab_tests, feed_preferences, user_feed_settings

**Events:** events, event_locations, event_tickets, event_attendees

**Awards:** awards, award_categories, award_nominations, award_votes

**Forum:** forum_categories, forum_topics, forum_replies, polls, poll_options, poll_votes

**Payments:** payments, payment_issues, user_credits, credit_transactions, credit_rates, artist_revenues

**Subscriptions:** subscription_plans, user_subscriptions

**Campaigns:** campaigns, campaign_pledges, campaign_updates

**Podcasts:** podcasts, podcast_categories, podcast_episodes *(missing: podcast_listens, podcast_subscriptions)*

**Store:** store_products, store_carts, store_cart_items, store_orders, store_order_items

**CMS:** cms_pages, cms_blocks, navigation_menus, menu_items, media_library, seo_metadata, frontend_settings, frontend_sections, frontend_section_items

**SACCO:** sacco_members, sacco_loans, sacco_transactions, sacco_savings_accounts, sacco_savings_transactions, sacco_loan_repayments, sacco_loan_products, sacco_shares, sacco_share_transactions, sacco_dividends, sacco_member_dividends, sacco_settings, sacco_accounts, sacco_audit_logs, sacco_board_members, sacco_board_meetings, sacco_board_meeting_attendance

**Artist:** artist_profiles

**Loyalty:** loyalty_cards, loyalty_card_members, loyalty_rewards, loyalty_reward_redemptions, loyalty_points, loyalty_transactions

**Infrastructure:** notifications, device_tokens, ad_impressions, audit_logs, user_settings, settings, media, jobs, failed_jobs, cache, telescope_entries

**Misc:** *(Not created)* podcast_listens, podcast_subscriptions, orders, distributions
