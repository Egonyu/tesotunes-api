# Notification Stability Tracker

## Goal

Standardize Tesotunes notification behavior around one reliable app contract:

- In-app notifications write to the custom `notifications` table via `App\Models\Notification`
- Mail and push are optional side effects and must never break core business flows
- Laravel's built-in `database` notification channel should not be used unless the schema is brought back to Laravel's default shape

## Canonical Contract

For the current codebase, a stable notification write should look like:

- `user_id`
- `type`
- `category`
- `title`
- `message`
- optional `action_url`
- optional `notifiable_type` and `notifiable_id`
- optional `actor_id`
- optional `priority`
- optional `data`

Avoid:

- `$user->notifications()->create(...)` with legacy or Laravel-database assumptions
- `notification_type` instead of `type`
- notification classes returning `database` in `via()` while the app still uses the custom `notifications` schema

## Completed

| ID | Area | Status | Notes |
| --- | --- | --- | --- |
| NTF-001 | Payments observer | Done | Replaced missing audit facade path, wrote custom in-app notifications directly, isolated mail failures |
| NTF-002 | Payment service legacy helpers | Done | Refund, subscription cancellation, and extension now use `App\Models\Notification` |

## Open Tracker

| ID | Priority | Area | Files | Anomaly | Risk | Status |
| --- | --- | --- | --- | --- | --- | --- |
| NTF-003 | P0 | Artist verification workflow | `app/Services/Auth/ArtistVerificationService.php` | Uses `$user->notifications()->create(...)` plus `notification_type` in approval, rejection, info-request, and admin-alert flows | Artist onboarding/admin review notifications can silently fail or write malformed rows | Done |
| NTF-004 | P0 | Social/auth welcome flow | `app/Services/Auth/SocialAuthService.php` | Writes welcome notification through `$user->notifications()->create(...)` against the wrong relation contract | New-user onboarding notifications are fragile and mask auth-domain issues | Done |
| NTF-005 | P0 | Social graph and feed reactions | `app/Models/Like.php`, `app/Models/Playlist.php`, `app/Models/Post.php`, `app/Http/Controllers/Api/Social/CommentController.php`, `app/Models/User.php` | Multiple social interactions still create notifications through the wrong relation path | High-volume user actions can create inconsistent notification rows and runtime failures | Done |
| NTF-006 | P1 | Music moderation and distribution | `app/Services/SongService.php`, `app/Services/DistributionService.php` | Mixed use of `notification_type`, `metadata`, and Laravel-style `type/data/read_at` assumptions | Artist-facing music workflow notifications are inconsistent and partially broken | Done |
| NTF-007 | P1 | Mobile notification readers/writers | `app/Http/Controllers/Api/Mobile/MobileSocialController.php` | Mobile code still references `notification_type` payload shape in response mapping/writes | Mobile clients may see missing or mismatched fields even after backend cleanup | Done |
| NTF-008 | P1 | Notification classes using `database` channel | `app/Notifications/Store/StorePaymentNotification.php`, `app/Notifications/Store/RefundNotification.php`, `app/Notifications/Store/OrderStatusNotification.php`, `app/Notifications/AdminSongPendingNotification.php`, `app/Notifications/Store/MonthlyReportNotification.php`, `app/Notifications/LoanStatusNotification.php`, `app/Notifications/PodcastStatusNotification.php` | These classes still declare `['mail', 'database']` against a non-Laravel notifications schema | Queued notifications can fail at runtime or drift from the app's custom notification table | Done |
| NTF-009 | P1 | Cross-module notification architecture | `app/Channels/AppNotificationChannel.php`, `app/Notifications/CrossModuleNotification.php`, `app/Notifications/SubscriptionNotification.php`, `app/Services/CrossModuleNotificationService.php` | Dynamic in-app delivery previously depended on Laravel's database channel instead of the custom app notification table | Ongoing feature work keeps reintroducing notification drift | Done |
| NTF-010 | P2 | Notification API consistency | `app/Http/Controllers/Api/NotificationController.php`, `app/Http/Controllers/Api/Mobile/MobileSocialController.php`, response standardization tests | API reads depend on current table shape but coverage is not yet tied to a canonical write contract | Done |
| NTF-011 | P2 | Factory/test stability | `database/factories/SubscriptionPlanFactory.php`, `database/factories/GenreFactory.php`, `database/factories/SongFactory.php`, other notification-adjacent factories | Some factories miss canonical required fields or drift from baseline columns, which hides real notification problems behind test fragility | Partial |
| NTF-012 | P0 | Artist profile baseline schema | `database/migrations/0001_01_01_000002_create_music_catalog_tables.php`, artist onboarding services/tests | `artist_profiles` was referenced widely but missing from the baseline schema | Artist onboarding and verification workflows can fail before notifications even run | Done |

## Working Order

1. NTF-011: complete the wider factory audit beyond the notification-adjacent factories already fixed

## Standardization Rules

- Add a small service or helper where repeated notification creation appears across a workflow
- Write in-app notifications explicitly through `App\Models\Notification`
- Wrap mail and push side effects in `try/catch (\Throwable)` if they are not the primary transaction outcome
- Add focused regression tests for each workflow migrated
- Prefer fixing factories and baseline schema where that reduces ambiguity instead of layering workaround migrations

## Next Slice

Start with `NTF-003` in `ArtistVerificationService` because it is business-critical, admin-linked, and currently contains four separate legacy notification writes.
