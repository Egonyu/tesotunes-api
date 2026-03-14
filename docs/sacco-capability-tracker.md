# SACCO Capability Tracker

## Purpose

This tracker maps the current SACCO ecosystem across:

- backend routes and controllers in `tesotunes-api`
- frontend hooks, pages, and types in `tesotunes-next-web`
- legacy surfaces that still exist but should not be treated as the long-term contract

It is intended to answer three questions quickly:

1. What works end-to-end today?
2. What exists on one side but not the other?
3. What should be stabilized, normalized, or rebuilt next?

## Status Legend

- `working`: backend exists, frontend contract is aligned enough for real use
- `partial`: backend exists, but frontend expects different endpoints or shapes
- `backend-only`: implemented in API, but not productized in frontend
- `frontend-only`: page/hook/type exists, but no matching backend contract exists
- `legacy`: working code path exists, but it is not the contract we should keep building on
- `rebuild`: concept exists, but current implementation is too inconsistent to scale safely

## Canonical Backend Surfaces

The SACCO system currently has three backend surfaces:

### 1. Canonical member API

This is the forward path and should be treated as the source of truth.

- Routes: `routes/api.php`
- Controllers:
  - `App\Http\Controllers\Api\Sacco\SaccoMembershipController`
  - `App\Http\Controllers\Api\Sacco\SaccoSavingsController`
  - `App\Http\Controllers\Api\Sacco\SaccoLoanController`
  - `App\Http\Controllers\Api\Sacco\SaccoSharesController`
  - `App\Http\Controllers\Api\Sacco\SaccoGoalsController`
  - `App\Http\Controllers\Api\Sacco\SaccoReportsController`
  - `App\Http\Controllers\Api\Sacco\SaccoAnalyticsController`
  - `App\Http\Controllers\Api\Sacco\SaccoIndexController`

### 2. Admin API

- Routes: `/api/admin/sacco/*`
- Controller:
  - `App\Http\Controllers\Api\Admin\SaccoApiController`
  - `App\Http\Controllers\Api\Admin\SaccoBoardMeetingsController`

This is real and useful, but its contract should be kept clearly separate from the member app API.

### 3. Legacy SACCO AJAX/mobile controller

- Controller:
  - `App\Http\Controllers\Api\SaccoApiController` `Retired`

Status: `retired`

Its useful read behaviors were absorbed into the canonical namespaced SACCO controllers. It should not be reintroduced.

## Frontend SACCO Surface

### User pages

- `(app)/sacco/page.tsx`
- `(app)/sacco/dashboard/page.tsx`
- `(app)/sacco/join/page.tsx`
- `(app)/sacco/savings/page.tsx`
- `(app)/sacco/savings/goals/page.tsx`
- `(app)/sacco/savings/goals/create/page.tsx`
- `(app)/sacco/savings/goals/[id]/page.tsx`
- `(app)/sacco/loans/page.tsx`
- `(app)/sacco/loans/apply/page.tsx`
- `(app)/sacco/loans/[id]/page.tsx`
- `(app)/sacco/shares/page.tsx`
- `(app)/sacco/contributions/page.tsx`
- `(app)/sacco/groups/page.tsx`
- `(app)/sacco/meetings/page.tsx`
- `(app)/sacco/fines/page.tsx`
- `(app)/sacco/withdrawals/page.tsx`
- `(app)/sacco/resources/page.tsx`
- `(app)/sacco/analytics/page.tsx`
- `(app)/sacco/community/page.tsx`
- `(app)/sacco/community/achievements/page.tsx`
- `(app)/sacco/community/challenges/page.tsx`
- `(app)/sacco/community/leaderboards/page.tsx`
- `(app)/sacco/community/stories/page.tsx`

### Admin pages

- `(admin)/admin/sacco/page.tsx`
- `(admin)/admin/sacco/board-meetings/page.tsx`
- `(admin)/admin/sacco/loans/[id]/page.tsx`
- `(admin)/admin/sacco/members/[id]/page.tsx`

### Shared frontend data layers

- `src/hooks/useSacco.ts`
- `src/hooks/useSaccoGoals.ts`
- `src/hooks/useSaccoAnalytics.ts`
- `src/hooks/useSaccoResources.ts`
- `src/types/sacco.ts`

## Capability Matrix

| Capability | Backend | Frontend | Status | Notes |
| --- | --- | --- | --- | --- |
| Membership lookup | `/api/sacco/membership` | `useSaccoMembership()` | `working` | Good baseline contract. |
| Join SACCO | `/api/sacco/join` | `useJoinSacco()` | `working` | Verified in lifecycle coverage. |
| Member directory CRUD | `/api/sacco/members*` | no clear member UI parity | `backend-only` | Likely admin/internal first. |
| Dashboard overview | `/api/sacco/dashboard` | `useSaccoDashboard()` | `working` | Canonical member summary endpoint. |
| Savings accounts open | `/api/sacco/savings/accounts` | no matching hook | `backend-only` | Frontend assumes account list endpoint instead. |
| Savings deposit | `/api/sacco/savings/deposit` | `useSaccoDeposit()` calls `/sacco/deposit` | `partial` | Path and payload mismatch. Backend wants `account_id`, not `phone_number`. |
| Savings withdraw | `/api/sacco/savings/withdraw` | `useSaccoWithdraw()` calls `/sacco/withdraw` | `partial` | Same mismatch pattern as deposit. |
| Savings account detail | `/api/sacco/savings/accounts/{account}` | no direct hook | `backend-only` | Useful for normalized savings dashboard. |
| Savings account transactions | `/api/sacco/savings/transactions/{account}` | `useSaccoTransactions()` calls `/sacco/transactions` | `partial` | Frontend expects consolidated feed; backend exposes account-scoped feed canonically and a legacy aggregate feed separately. |
| Savings balance | `/api/sacco/savings/balance/{account}` | no direct hook | `backend-only` | Useful for wallet/account panels. |
| Loan list | `/api/sacco/loans` | `useSaccoLoans()` | `working` | Core flow present. |
| Loan detail | `/api/sacco/loans/{loan}` | `useSaccoLoan()` | `working` | Shape is close enough. |
| Loan apply | `/api/sacco/loans/apply` | `useApplyForLoan()` | `partial` | Frontend sends `product_id`, `amount`, `term_months`; backend expects `member_id`, `principal_amount_ugx`, `tenure_months`. |
| Loan repay | `/api/sacco/loans/{loan}/repay` | `useMakeLoanPayment()` calls `/sacco/loans/{id}/pay` | `partial` | Wrong path and payload assumptions. |
| Loan approve/disburse | `/api/sacco/loans/{loan}/approve`, `/disburse` | admin and lifecycle only | `backend-only` | Needed for admin workflows. |
| Loan schedule | `/api/sacco/loans/{loan}/schedule` | no direct hook | `backend-only` | Good candidate for loan detail UX. |
| Loan balance | `/api/sacco/loans/{loan}/balance` | no direct hook | `backend-only` | Useful for payoff panels. |
| Shares purchase | `/api/sacco/shares/purchase` | `useBuyShares()` calls `/sacco/shares/buy` | `partial` | Path and payload mismatch. |
| Shares transfer | `/api/sacco/shares/transfer` | no matching member hook | `backend-only` | Could stay admin/member advanced. |
| Member shares | `/api/sacco/shares/member/{member}` | `useSaccoShares()` calls `/sacco/shares` | `partial` | Frontend expects self endpoint; backend uses member ID endpoint plus market value endpoint. |
| Share market value | `/api/sacco/shares/value` | no direct dedicated hook | `backend-only` | Needed by member shares summary. |
| Goals CRUD | `/api/sacco/goals*` | `useSaccoGoals*` | `working` | This is the cleanest aligned sub-domain after membership. |
| Goal deposits | `/api/sacco/goals/{goal}/deposit` | `useGoalDeposit()` | `working` | Path and response are aligned enough. |
| Goal credit conversion | `/api/sacco/goals/{goal}/convert-credits` | `useGoalConvertCredits()` | `working` | Present and coherent. |
| Goal auto-save settings | `/api/sacco/goals/{goal}/auto-save` | `useUpdateAutoSave()` | `working` | Good candidate as the pattern for other flows. |
| Goal transactions | `/api/sacco/goals/{goal}/transactions` | `useGoalTransactions()` | `partial` | Backend returns paginator object directly, not the exact typed frontend wrapper. |
| Goal funding options | `/api/sacco/goals/{goal}/funding-options` | `useGoalFundingOptions()` | `working` | Minimal but aligned. |
| Reports | `/api/sacco/reports/*` | no productized frontend | `backend-only` | Valuable for admin and exports; admin report UI is now intentionally marked as rebuild-in-progress. |
| Analytics dashboard and finance analytics | `/api/sacco/analytics/*` | frontend expects production and gamification analytics | `partial` | Different concepts under the same "analytics" label. |
| Admin stats/members/loans | `/api/admin/sacco/*` | admin SACCO pages exist | `working` | Landing page, member detail, loan detail, repayments, and review actions now align to canonical admin routes. |
| Board meetings admin | `/api/admin/sacco/board-meetings*` | admin board meeting pages exist | `working` | Board member list and meeting list/show now use normalized admin contracts with governance-friendly status mapping. |
| Governance meetings admin | `/api/admin/sacco/meetings*` | dedicated UI not built yet | `backend-only` | Canonical CRUD, attendance operations, and resolution/minutes management are now live for the wider governance module. |
| Contributions | no canonical route | `useSaccoContributions()` | `frontend-only` | Needs product decision before build. |
| Groups | no canonical route | `useSaccoGroups()` | `frontend-only` | Community module, not finance core. |
| Meetings member RSVP | `/api/sacco/meetings*` | `useSaccoMeetings()`, `useRsvpMeeting()` | `working` | Members can now browse meetings, RSVP, and review minutes/resolutions from a live governance feed. |
| Fines | no canonical route | `useSaccoFines()`, `usePaySaccoFine()` | `frontend-only` | Needs governance and accounting rules first. |
| Withdrawal requests | no canonical route | `useSaccoWithdrawalRequests()`, `useCreateWithdrawalRequest()` | `frontend-only` | Separate concept from savings withdrawal and needs approval workflow. |
| Platform resources | no canonical route | `useSaccoResources*` | `frontend-only` | This is a platform resource marketplace, not current SACCO finance API. |
| Resource loans | no canonical route | `useResourceLoan*` | `frontend-only` | Rich concept, no backend foundation yet. |
| Gamification | no canonical route | achievements, badges, leaderboards, challenges, streak | `frontend-only` | Product experience layer, not implemented server-side. |
| Recommendations | no canonical route | `useSaccoRecommendations()` | `frontend-only` | Needs data science/recommendation service design first. |
| Community stories and group goals | no canonical route | `useSuccessStories()`, `useGroupGoals()` | `frontend-only` | Aspirational community finance layer. |
| Credit savings system | no canonical route | `useCreditSavingsSystem()`, `useCreditConversion()` | `frontend-only` | Partially overlaps with goal credit conversion but is not normalized. |
| Production analytics | no canonical route | `useProductionAnalytics()` and related hooks | `frontend-only` | Frontend models a creator-finance intelligence layer not yet backed by API. |

## Highest-Value Gaps

### 1. Savings contract is split between canonical and legacy designs

Current reality:

- canonical API is account-centric
- frontend expects a member-centric wallet summary
- legacy controller contains a consolidated transaction feed and account summaries

Recommendation:

- keep the canonical savings write routes
- add canonical read endpoints for:
  - `GET /api/sacco/savings`
  - `GET /api/sacco/transactions`
- make them wrappers over canonical models, not over the legacy controller
- then migrate frontend hooks to the canonical surface and retire the legacy read endpoints

### 2. Loan application and repayment payloads are not normalized

Current reality:

- frontend uses consumer-friendly fields like `amount`, `term_months`, `loan_id`
- backend uses schema-level fields like `member_id`, `principal_amount_ugx`, `tenure_months`

Recommendation:

- choose one public API vocabulary and make the backend accept it
- derive `member_id` from auth where possible
- keep storage naming internal to models/resources, not external API payloads

### 3. Shares need a self-service read contract

Current reality:

- backend supports purchase, transfer, member-specific detail, and market value
- frontend expects a single self-shares endpoint

Recommendation:

- add `GET /api/sacco/shares`
- shape it as:
  - current member holdings
  - current share price
  - recent purchases or transfers

### 4. Analytics means two different things today

Current reality:

- backend analytics are financial and operational
- frontend analytics are production finance, gamification, recommendations, and creator performance

Recommendation:

- split analytics into bounded domains:
  - `finance-analytics`
  - `member-engagement`
  - `creator-production`
- do not keep growing unrelated concepts under `/sacco/analytics/*`

### 5. The frontend contains a large aspirational SACCO product that has no backend foundation

These areas should not be treated as regressions. They are roadmap items:

- contributions
- groups
- member meetings and RSVP
- fines
- withdrawal requests
- resources and resource loans
- community stories and group goals
- recommendations
- gamification
- production analytics

Current frontend handling:

- removed from the primary SACCO sidebar navigation
- replaced with explicit planned-state pages instead of live-looking broken modules

These should either move to:

- a `labs` or `planned` product state in frontend
- or a separate module namespace if they are not strictly SACCO finance

## Normalized Target Architecture

### Domain 1. Membership and onboarding

Stable, member-authenticated, low-friction

- membership status
- join
- member profile
- member summary

### Domain 2. Savings ledger

The member wallet/accounting core

- accounts
- balances
- deposits
- withdrawals
- transactions feed
- withdrawal approvals if that concept is adopted

### Domain 3. Credit and loans

Formal lending lifecycle

- loan products
- eligibility
- applications
- approvals
- disbursements
- repayment
- schedules
- guarantors

### Domain 4. Shares and capital

- purchases
- transfers
- self holdings
- market value
- dividends

### Domain 5. Goals and creator financing

This is the bridge between SACCO finance and the TesoTunes creator ecosystem.

- savings goals
- goal deposits
- goal credit conversion
- funding options
- later: creator project funding, co-funding, resource access

### Domain 6. Admin and governance

- admin members
- admin loans
- admin transactions
- board meetings
- reports
- risk and portfolio analytics

### Domain 7. Experience layer

Only after the finance core is stable

- community
- achievements
- challenges
- recommendations
- creator production analytics
- resource marketplace

## Recommended Tracker Board

### Green: keep and harden

- membership
- join
- goals CRUD and goal funding flows
- admin stats and loan operations
- board meetings admin

### Yellow: normalize next

- dashboard summary
- savings read model
- savings deposit and withdraw payloads
- loan apply payload
- loan repay route
- self shares read model
- goal transactions pagination contract
- analytics naming and boundaries

### Red: redesign before implementation

- contributions
- groups
- member meetings
- fines
- withdrawal requests
- resources and resource loans
- gamification
- recommendations
- production analytics
- community group goals and stories

## Immediate Next Actions

1. Add canonical read endpoints for member dashboard, savings summary, self shares, and consolidated transactions. `Done`
2. Normalize frontend SACCO hooks to the canonical routes instead of legacy or imagined paths. `Done`
3. Lift `loan products`, `eligibility`, and `profile` out of the legacy `SaccoApiController` into `App\Http\Controllers\Api\Sacco\...`. `Done`
4. Lift remaining useful loan utilities from legacy into canonical routes:
   - guarantor discovery
   - product-specific eligibility detail
   - repayment schedule preview
   Status: `Done`
5. Mark frontend-only SACCO features as planned or hidden until backend foundations exist. `Next`
6. Add contract tests for the stabilized member endpoints so frontend and backend stop drifting. `Done`

## Working Definition Of Done

The SACCO ecosystem should be considered stable when:

- every visible frontend SACCO feature maps to a real backend contract
- every backend SACCO contract has a clear owning domain
- legacy `App\Http\Controllers\Api\SaccoApiController` remains retired
- member finance flows are canonical and tested
- aspirational creator/community features are clearly separated from core SACCO finance
