# Events Module Audit Tracker

## Scope

- Backend: `tesotunes-api`
- Frontend: `../tesotunes-next-web`
- Goal: audit the full Events module as one Tesotunes business capability, not a standalone feature

## Module Boundaries

Events already touch these Tesotunes domains:

- Music and artist identity: organizers, artist profiles, artist dashboards
- Payments and credits: wallet, mobile money, credits, payout logic
- Loyalty and fan clubs: gated tiers, early access, check-in rewards
- Social and feed: likes, bookmarks, comments, shares, interest, feed cards
- Notifications: ticket confirmations, reminders, cancellation notices
- Admin governance: moderation, publication, featuring, reporting
- Growth and commerce: group booking, referrals, discounting, campaigns

## Current Inventory

### Backend

- Public read APIs: `PublicEventsController`
- Artist management APIs: `ArtistEventsController`
- Admin management APIs: `EventsApiController`
- Ticketing APIs: `TicketController`
- Core models: `Event`, `EventTicket`, `EventAttendee`, `EventLocation`
- Supporting pieces: `EventResource`, `EventObserver`, `AwardEventLoyaltyPoints`, `SendEventNotifications`, `EventSettingsService`
- Schema source of truth: `database/migrations/0001_01_01_000002_create_music_catalog_tables.php`

### Frontend

- Public pages: `/events`, `/events/[id]`, `/events/[id]/checkout`, `/events/check-in`
- Artist pages: `/artist/events`, `/artist/events/create`, `/artist/events/[id]`, `/artist/events/[id]/edit`
- Admin pages: `/admin/events`, `/admin/events/new`, `/admin/events/[id]`, `/admin/events/[id]/edit`
- Shared event client: `src/hooks/useEvents.ts`
- Event state: `src/stores/events.ts`
- Event UI: `src/components/events/*`
- Extra public promise: `/artists/[slug]/events`

## Findings

### P0 Critical

1. Ticket purchase validation points to a non-existent table, so paid checkout is broken at entry.
   - Backend reference: `app/Http/Controllers/Api/TicketController.php:21-24`
   - Schema reference: `database/migrations/0001_01_01_000002_create_music_catalog_tables.php:380-398`
   - Problem: validation uses `exists:event_ticket_types,id` while the real table is `event_tickets`.

2. Ticket routes expose `validate`, but the controller method is named `validate_`, so `/api/tickets/validate/{ticketNumber}` cannot resolve.
   - Route reference: `routes/api.php:62-67`
   - Controller reference: `app/Http/Controllers/Api/TicketController.php:274-301`

3. Core ticketing logic reads and writes columns that do not exist in the consolidated schema.
   - `quantity_reserved`, `sales_start_at`, `sales_end_at`: `app/Models/EventTicket.php:17-45`, `app/Models/EventTicket.php:102-116`, `app/Models/EventTicket.php:190-203`
   - `ticket_type_id`, `event_ticket_id`, payment metadata fields: `app/Models/EventAttendee.php:15-44`
   - Schema only defines `event_tickets.sale_starts_at`, `event_tickets.sale_ends_at`, and `event_attendees.ticket_id`: `database/migrations/0001_01_01_000002_create_music_catalog_tables.php:380-411`
   - Impact: checkout, inventory, refunds, and analytics all operate on an inconsistent data model.

4. The event model depends on missing runtime objects and fields, so several helper paths will fatal or silently misbehave.
   - Missing relation target: `app/Models/Event.php:131-134` references `EventStatistics`
   - Missing route target: `app/Models/Event.php:343-350` references `frontend.events.show`
   - Missing event fields through helper methods: `app/Models/Event.php:399-460` uses `price`, `quantity_remaining`, and other legacy ticket fields that are not part of the current schema contract

5. Event interest is implemented in the API, but its pivot table is missing from migrations.
   - User relation: `app/Models/User.php:451`
   - Toggle endpoint: `app/Http/Controllers/Api/ActivityInteractionController.php:126-150`
   - Migration search result: no `event_interests` table exists in `database/migrations`

### P1 High

6. The frontend advertises admin actions and pages that the backend does not expose.
   - Frontend calls:
     - `../tesotunes-next-web/src/app/(admin)/admin/events/[id]/page.tsx:246`
     - `../tesotunes-next-web/src/app/(admin)/admin/events/[id]/page.tsx:253`
     - `../tesotunes-next-web/src/app/(admin)/admin/events/[id]/page.tsx:518`
     - `../tesotunes-next-web/src/app/(admin)/admin/events/[id]/page.tsx:525`
   - Backend routes only expose index/show/store/update/destroy/stats/registrations:
     - `routes/api.php:357-363`
   - Missing contracts: toggle featured, publish, attendees page data, analytics page data.

7. The frontend promises artist public event pages that the backend does not implement.
   - Frontend request: `../tesotunes-next-web/src/app/(app)/artists/[slug]/events/page.tsx:182`
   - Expected shape also includes `tickets_url`, `interested_count`, `is_attending`, `is_interested`, and status values like `upcoming/live/past`: `../tesotunes-next-web/src/app/(app)/artists/[slug]/events/page.tsx:18-40`
   - No matching backend route/controller exists in this repo.

8. The checkout flow only submits the first selected tier, while the cart allows multiple ticket tiers.
   - Cart supports many items: `../tesotunes-next-web/src/stores/events.ts:17-46`, `../tesotunes-next-web/src/stores/events.ts:70-100`
   - Checkout sends only `items[0]`: `../tesotunes-next-web/src/app/(app)/events/[id]/checkout/page.tsx:119`
   - Impact: UI implies mixed-tier checkout, API only receives one tier.

9. Payment method contracts are inconsistent between UI state and API.
   - Store default is `'ugx'`: `../tesotunes-next-web/src/stores/events.ts:61`
   - Backend only accepts `wallet`, `mtn_momo`, `airtel_money`, `card`, `credits`: `app/Http/Controllers/Api/TicketController.php:25`
   - `useCompleteCheckout` forwards `payment_provider` directly into `payment_method`: `../tesotunes-next-web/src/hooks/useEvents.ts:864-885`
   - Impact: the default checkout path can submit an invalid payment method.

10. Event resource and frontend normalization still rely on mixed legacy/current field names, so the contract is not stable enough for long-term reuse.
   - Resource: `app/Http/Resources/EventResource.php:52-80`
   - Frontend normalization: `../tesotunes-next-web/src/hooks/useEvents.ts:271-380`
   - Symptom: `image`, `artwork`, `banner`, `venue`, `location`, `date`, `starts_at`, `capacity`, and `attendee_limit` all coexist.

### P2 Medium

11. Event observer/feed integration uses fields that are not consistently present on the current event contract.
   - `app/Observers/EventObserver.php:16-49`
   - Uses `venue`, `banner_url`, `image_url`, `/events/{slug}` assumptions, while the actual resource centers on `venue_name`, `artwork`, `banner`, and numeric `id`.

12. Event settings service calculates revenue using schema names that no longer match the event ticket model.
   - `app/Services/Settings/EventSettingsService.php:399-417`
   - It joins `event_attendees.ticket_id` correctly but sums `event_tickets.price`, while the actual ticket table stores `price_ugx`.

13. Public listing ignores several filter promises already present in frontend state.
   - Frontend filters support `city`, date range, price range, virtual/free, sort: `../tesotunes-next-web/src/types/events.ts`
   - Public API currently supports only `search`, `category`, and `upcoming=true`: `app/Http/Controllers/Api/PublicEventsController.php:14-33`

14. The frontend exposes placeholder capabilities with no backend contract yet.
   - Group bookings, discount validation, live check-in, event recommendations:
     - `../tesotunes-next-web/src/hooks/useEvents.ts:752-765`
     - `../tesotunes-next-web/src/hooks/useEvents.ts:895-987`
   - These should be explicitly marked as planned features, not implied production behavior.

## Contract Gaps: Frontend Promise vs Backend Truth

| Area | Frontend promise | Backend truth | Decision |
| --- | --- | --- | --- |
| Public event detail | Event + ticket tiers + social proof + comments + likes + follows | Event + ticket tiers exist; social counters are partial; comments/likes are generic; follow-on-event is not clearly productized | Keep detail page, trim unsupported social promises or finish backend |
| Artist public events | `/artists/{slug}/events` | No backend route | Build route or remove page from nav/indexing |
| Admin event actions | Publish, feature toggle, attendees, analytics pages | Only CRUD + stats + registrations | Add endpoints or remove buttons |
| Checkout | Multi-tier cart, hybrid language, discount code, complete purchase | Single purchase endpoint, one tier at a time, no hybrid/discount/group booking contract | Reduce UI promise now, expand backend later |
| Ticket validation | Scanner/check-in flow | Route-method mismatch currently breaks validation | Fix backend immediately |
| Event interest | Toggle interest | Pivot table missing | Add migration and tests |

## Standardization Target

### Canonical backend entities

- `events`
  - Organizer-owned event record
  - One canonical schedule model: `starts_at`, `ends_at`, `timezone`, `doors_open_at`
  - One canonical venue model: `is_virtual`, `virtual_link`, `event_location_id`, plus denormalized `venue_name`, `venue_address`, `city`, `country`
- `event_tickets`
  - One canonical price model: `price_ugx`, `price_credits`
  - One canonical inventory model: `quantity_total`, `quantity_sold`
  - One canonical sales window model: `sale_starts_at`, `sale_ends_at`
- `event_attendees`
  - One canonical foreign key: `ticket_id`
  - One canonical attendee state machine: `pending`, `confirmed`, `attended`, `cancelled`, `refunded`, `no_show`
  - One canonical payment snapshot: `price_paid_ugx`, `price_paid_credits`, `payment_method`, `payment_reference`

### Canonical API contract

- Public
  - `GET /api/events`
  - `GET /api/events/featured`
  - `GET /api/events/upcoming`
  - `GET /api/events/categories`
  - `GET /api/events/{id}`
- Ticketing
  - `POST /api/tickets/purchase`
  - `GET /api/tickets/my`
  - `GET /api/tickets/{id}`
  - `GET /api/tickets/validate/{ticketNumber}`
  - `POST /api/tickets/check-in`
- Artist
  - `GET /api/artist/events`
  - `POST /api/artist/events`
  - `GET /api/artist/events/{id}`
  - `PUT /api/artist/events/{id}`
  - `DELETE /api/artist/events/{id}`
  - `GET /api/artist/events/{id}/analytics`
- Admin
  - Keep existing CRUD and add only if product-approved:
    - `POST /api/admin/events/{id}/publish`
    - `POST /api/admin/events/{id}/toggle-featured`
    - `GET /api/admin/events/{id}/analytics`
    - `GET /api/admin/events/{id}/attendees`

### Canonical frontend shape

- Retire alias-heavy event mapping over time
- Prefer one stable object:
  - `id`, `slug`, `title`, `description`
  - `artwork`, `banner`
  - `starts_at`, `ends_at`, `timezone`
  - `is_virtual`, `virtual_link`
  - `venue_name`, `venue_address`, `city`, `country`
  - `organizer`
  - `ticket_tiers`
  - `attendee_count`, `tickets_sold`
  - `status`, `is_featured`
- Keep UI-only derived fields local to components:
  - `date`
  - `time`
  - `venue`
  - `image`

## Business Logic Rules To Standardize

1. Events are organizer-owned content objects and should align with artist/admin moderation rules used elsewhere in Tesotunes.
2. Ticket purchases should flow through one payment orchestration path that supports wallet, credits, and mobile money without duplicating fee logic in the UI.
3. Loyalty benefits must use the same source-of-truth tier logic as fan clubs and loyalty cards.
4. Social engagement should be intentional:
   - interest = planning intent
   - purchase = confirmed attendance
   - check-in = physical or verified attendance
   - like/bookmark/comment/share = content engagement
5. Events should publish feed items and notifications only from stable lifecycle transitions:
   - published
   - ticket purchased
   - reminder due
   - cancelled
   - checked in
6. Event revenue should be eligible for cross-module reporting and SACCO/product financing analytics only after payment confirmation.

## Recommended Implementation Order

### Phase 1: Stabilize the backend contract

- Fix `event_ticket_types` -> `event_tickets`
- Rename `validate_` to `validate`
- Align `EventTicket` and `EventAttendee` models to the consolidated migration
- Add missing `event_interests` migration
- Remove or replace missing `EventStatistics` and `frontend.events.show` references
- Add contract tests for public, ticket, artist, and admin event APIs

### Phase 2: Trim or fulfill frontend promises

- Remove unsupported admin buttons or implement their endpoints
- Either implement `/artists/{slug}/events` or hide the public artist-events page
- Restrict checkout UI to one supported purchase mode until backend supports multi-tier/group/hybrid flows
- Change default payment method from `'ugx'` to a backend-supported value
- Mark planned features as planned in code and UI copy

### Phase 3: Standardize business workflows

- Centralize event fee calculation in backend services
- Add organizer publication/feature workflow
- Add reminder/cancellation notification jobs
- Formalize attendee status transitions
- Add check-in authorization rules and audit logs

### Phase 4: Expand module intelligence

- Group bookings
- Discount codes
- Referral links for events
- Live event check-in rewards
- Better analytics and feed/social proof

## Tracker

| ID | Priority | Item | Type | Status |
| --- | --- | --- | --- | --- |
| EVT-001 | P0 | Fix purchase validation table name in `TicketController` | Bug | Done |
| EVT-002 | P0 | Rename `TicketController::validate_` to `validate` and cover with test | Bug | Done |
| EVT-003 | P0 | Align attendee/ticket models with consolidated schema | Standardization | Done |
| EVT-004 | P0 | Add `event_interests` migration and contract tests | Migration | Done |
| EVT-005 | P0 | Remove missing `EventStatistics` relation or implement it explicitly | Bug | Done |
| EVT-006 | P0 | Replace `frontend.events.show` dependency with a safe URL strategy | Bug | Done |
| EVT-007 | P1 | Decide whether admin publish/feature/analytics/attendees actions are in scope | Product/API | Done |
| EVT-008 | P1 | Decide whether `/artists/{slug}/events` is a real public feature | Product/API | Done |
| EVT-009 | P1 | Fix frontend checkout to support exactly what backend supports today | Contract | Done |
| EVT-010 | P1 | Change frontend event cart default payment method to a supported backend value | Bug | Done |
| EVT-011 | P1 | Collapse alias-heavy event DTO usage into one stable frontend contract | Standardization | Done |
| EVT-012 | P2 | Align event analytics, observer, and settings services with canonical event fields | Refactor | Done |
| EVT-013 | P2 | Add response-standardization tests for Events APIs | Test gap | Done |
| EVT-014 | P2 | Add clear product flags or copy for planned event features | UX | Done |

## Suggested Definition of Done

- All event flows use the consolidated schema only
- Public, artist, admin, and ticket event routes each have contract tests
- Frontend pages call only real endpoints
- Checkout and check-in succeed end-to-end
- Event state changes publish the right feed and notification side effects
- Loyalty, payments, and analytics use shared services rather than duplicated UI logic
