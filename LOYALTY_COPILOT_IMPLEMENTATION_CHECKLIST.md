# Loyalty System - Copilot Implementation Quick-Start Checklist

## 🎯 Overview
This checklist provides step-by-step implementation instructions for GitHub Copilot to build the TesoTunes Loyalty System (Artist Fan Clubs). Follow phases sequentially.

**Current Status**: 15% scaffolded (empty observers/listeners), 85% missing
**Target**: Full loyalty system with Next.js frontend + Laravel API backend
**Timeline**: 14 weeks (7 phases × 2 weeks each)

---

## Phase 1: Database & Models (Week 1-2)

### Step 1.1: Create Migrations

```bash
# Run these commands in terminal:
php artisan make:migration create_loyalty_cards_table
php artisan make:migration create_loyalty_card_members_table
php artisan make:migration create_loyalty_rewards_table
php artisan make:migration create_loyalty_reward_redemptions_table
php artisan make:migration create_loyalty_points_table
php artisan make:migration create_loyalty_transactions_table
```

**Files to Create:**
- [ ] `database/migrations/YYYY_MM_DD_create_loyalty_cards_table.php`
- [ ] `database/migrations/YYYY_MM_DD_create_loyalty_card_members_table.php`
- [ ] `database/migrations/YYYY_MM_DD_create_loyalty_rewards_table.php`
- [ ] `database/migrations/YYYY_MM_DD_create_loyalty_reward_redemptions_table.php`
- [ ] `database/migrations/YYYY_MM_DD_create_loyalty_points_table.php`
- [ ] `database/migrations/YYYY_MM_DD_create_loyalty_transactions_table.php`

**Schema Details** (Copy from LOYALTY_SYSTEM_AUDIT_AND_REBUILD.md Section 3.1):
- `loyalty_cards`: id, uuid, artist_id, name, slug, description, logo_url, banner_url, primary_color, secondary_color, tiers (JSON), status, published_at, total_members, monthly_revenue, allow_monthly, allow_yearly, auto_renew, timestamps
- `loyalty_card_members`: id, loyalty_card_id, user_id, tier, subscription_type, price_paid, currency, status, joined_at, expires_at, renewed_at, cancelled_at, auto_renew, payment_method, payment_transaction_id, total_renewals, lifetime_value, timestamps
- `loyalty_rewards`: id, loyalty_card_id, name, description, type, required_tier, content_type, content_url, product_id, discount_percentage, event_id, experience_type, points_amount, is_active, available_from, available_until, max_redemptions, current_redemptions, timestamps
- `loyalty_reward_redemptions`: id, loyalty_reward_id, user_id, loyalty_card_member_id, status, fulfilled_at, fulfilment_notes, timestamps
- `loyalty_points`: id, user_id (unique), balance, lifetime_earned, lifetime_spent, current_multiplier, timestamps
- `loyalty_transactions`: id, user_id, type, points, balance_after, source, source_id, source_type, description, base_points, multiplier, created_at

### Step 1.2: Create Eloquent Models

```bash
php artisan make:model Models/Loyalty/LoyaltyCard
php artisan make:model Models/Loyalty/LoyaltyCardMember
php artisan make:model Models/Loyalty/LoyaltyReward
php artisan make:model Models/Loyalty/LoyaltyRewardRedemption
php artisan make:model LoyaltyPoints
php artisan make:model LoyaltyTransaction
```

**Files to Create:**
- [ ] `app/Models/Loyalty/LoyaltyCard.php`
- [ ] `app/Models/Loyalty/LoyaltyCardMember.php`
- [ ] `app/Models/Loyalty/LoyaltyReward.php`
- [ ] `app/Models/Loyalty/LoyaltyRewardRedemption.php`
- [ ] `app/Models/LoyaltyPoints.php`
- [ ] `app/Models/LoyaltyTransaction.php`

**Key Relationships to Add:**

```php
// LoyaltyCard.php
public function artist() { return $this->belongsTo(Artist::class); }
public function members() { return $this->hasMany(LoyaltyCardMember::class); }
public function rewards() { return $this->hasMany(LoyaltyReward::class); }
public function events() { return $this->hasMany(Event::class); }

// LoyaltyCardMember.php
public function loyaltyCard() { return $this->belongsTo(LoyaltyCard::class); }
public function user() { return $this->belongsTo(User::class); }
public function redemptions() { return $this->hasMany(LoyaltyRewardRedemption::class); }

// LoyaltyReward.php
public function loyaltyCard() { return $this->belongsTo(LoyaltyCard::class); }
public function product() { return $this->belongsTo(Product::class); }
public function event() { return $this->belongsTo(Event::class); }
public function redemptions() { return $this->hasMany(LoyaltyRewardRedemption::class); }

// LoyaltyPoints.php
public function user() { return $this->belongsTo(User::class); }
public function transactions() { return $this->hasMany(LoyaltyTransaction::class, 'user_id', 'user_id'); }

// LoyaltyTransaction.php
public function user() { return $this->belongsTo(User::class); }
```

**Scopes to Add:**

```php
// LoyaltyCard.php
public function scopeActive($query) { return $query->where('status', 'active'); }
public function scopeByArtist($query, $artistId) { return $query->where('artist_id', $artistId); }

// LoyaltyCardMember.php
public function scopeActive($query) { return $query->where('status', 'active'); }
public function scopeExpiring($query, $days = 7) { return $query->where('expires_at', '<=', now()->addDays($days)); }

// LoyaltyReward.php
public function scopeActive($query) { return $query->where('is_active', true); }
public function scopeForTier($query, $tier) { return $query->where('required_tier', $tier); }
```

### Step 1.3: Create Factories & Seeders

```bash
php artisan make:factory Loyalty/LoyaltyCardFactory --model=Models/Loyalty/LoyaltyCard
php artisan make:factory Loyalty/LoyaltyCardMemberFactory --model=Models/Loyalty/LoyaltyCardMember
php artisan make:seeder LoyaltySeeder
```

**Files to Create:**
- [ ] `database/factories/Loyalty/LoyaltyCardFactory.php`
- [ ] `database/factories/Loyalty/LoyaltyCardMemberFactory.php`
- [ ] `database/seeders/LoyaltySeeder.php`

**Sample Tiers JSON for Seeder:**
```php
'tiers' => [
    'bronze' => [
        'name' => 'Bronze Fan',
        'price_monthly' => 5000,
        'price_yearly' => 50000,
        'benefits' => [
            'event_discount_percentage' => 5,
            'early_access_hours' => 0,
            'exclusive_content' => false,
            'store_discount_percentage' => 0,
            'loyalty_points_multiplier' => 1,
            'badge_icon' => 'bronze_star.svg'
        ]
    ],
    'silver' => [
        'name' => 'Silver VIP',
        'price_monthly' => 10000,
        'price_yearly' => 100000,
        'benefits' => [
            'event_discount_percentage' => 10,
            'early_access_hours' => 24,
            'exclusive_content' => true,
            'store_discount_percentage' => 10,
            'loyalty_points_multiplier' => 1.5,
            'badge_icon' => 'silver_star.svg'
        ]
    ],
    'gold' => [
        'name' => 'Gold Elite',
        'price_monthly' => 20000,
        'price_yearly' => 200000,
        'benefits' => [
            'event_discount_percentage' => 20,
            'early_access_hours' => 48,
            'exclusive_content' => true,
            'store_discount_percentage' => 15,
            'loyalty_points_multiplier' => 2,
            'badge_icon' => 'gold_star.svg'
        ]
    ]
]
```

### Step 1.4: Run Migrations & Seed

```bash
php artisan migrate
php artisan db:seed --class=LoyaltySeeder
```

---

## Phase 2: Services & Business Logic (Week 3-4)

### Step 2.1: Create TierAccessService

```bash
php artisan make:service Loyalty/TierAccessService
```

**File to Create:**
- [ ] `app/Services/Loyalty/TierAccessService.php`

**Methods to Implement:**

```php
namespace App\Services\Loyalty;

class TierAccessService
{
    /**
     * Check if user can access an event
     * @return array ['can_access' => bool, 'reason' => string|null, 'membership' => LoyaltyCardMember|null]
     */
    public function canAccessEvent(User $user, Event $event): array
    {
        // 1. Check if event requires loyalty tier
        // 2. Get user's membership for this loyalty card
        // 3. Compare tier levels (bronze=1, silver=2, gold=3, platinum=4)
        // 4. Return access decision
    }

    /**
     * Check if user can purchase ticket with discount
     * @return array ['can_access' => bool, 'discount' => array|null, 'has_early_access' => bool]
     */
    public function canPurchaseTicket(User $user, EventTicket $ticket): array
    {
        // 1. Check event access first
        // 2. Get tier discount from ticket->tier_discounts JSON
        // 3. Calculate discounted price
        // 4. Check early access eligibility
        // 5. Return access + pricing info
    }

    /**
     * Check if user has early access to ticket sales
     * @return array ['has_early_access' => bool, 'early_access_starts_at' => Carbon|null, 'hours_advantage' => int]
     */
    public function hasEarlyAccess(User $user, EventTicket $ticket): array
    {
        // 1. Get user's membership
        // 2. Get tier benefits from loyalty card
        // 3. Calculate early access start time (public sale - early_access_hours)
        // 4. Return early access details
    }

    /**
     * Filter events query to show only accessible events
     */
    public function scopeAccessibleEvents($query, User $user)
    {
        // 1. Get user's active memberships (loyalty_card_id => tier)
        // 2. Return events WHERE required_loyalty_tier IS NULL OR (loyalty_card_id IN user's memberships AND tier level is sufficient)
    }

    /**
     * Get user's highest tier level across all memberships
     */
    protected function getUserHighestTierLevel(User $user): int
    {
        // bronze=1, silver=2, gold=3, platinum=4
        // Return max tier level
    }

    /**
     * Get tier level from tier name
     */
    protected function getTierLevel(string $tier): int
    {
        $levels = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4];
        return $levels[$tier] ?? 0;
    }
}
```

### Step 2.2: Create LoyaltyPointsService

```bash
mkdir -p app/Services/Loyalty
touch app/Services/Loyalty/LoyaltyPointsService.php
```

**File to Create:**
- [ ] `app/Services/Loyalty/LoyaltyPointsService.php`

**Methods to Implement:**

```php
namespace App\Services\Loyalty;

class LoyaltyPointsService
{
    /**
     * Award points to user
     * @param User $user
     * @param int $basePoints - Points before multiplier
     * @param string $source - 'stream', 'download', 'purchase', 'event_attendance', 'referral'
     * @param mixed $sourceId - ID of related record
     */
    public function awardPoints(User $user, int $basePoints, string $source, $sourceId = null): void
    {
        // 1. Get or create LoyaltyPoints record
        // 2. Get user's highest loyalty points multiplier from active memberships
        // 3. Calculate points: basePoints × multiplier
        // 4. Update balance and lifetime_earned
        // 5. Create LoyaltyTransaction record
    }

    /**
     * Spend points
     */
    public function spendPoints(User $user, int $points, string $reason, $sourceId = null): bool
    {
        // 1. Check if user has sufficient balance
        // 2. Deduct points
        // 3. Update lifetime_spent
        // 4. Create LoyaltyTransaction record with negative points
    }

    /**
     * Get user's current multiplier (highest from active memberships)
     */
    public function getUserMultiplier(User $user): float
    {
        // 1. Get active memberships
        // 2. Extract multipliers from each loyalty card's tiers JSON
        // 3. Return max multiplier (or 1.0 if no memberships)
    }

    /**
     * Get points balance
     */
    public function getBalance(User $user): int
    {
        return $user->loyaltyPoints?->balance ?? 0;
    }

    /**
     * Convert points to credits (optional feature)
     */
    public function convertPointsToCredits(User $user, int $points): bool
    {
        // 100 points = 10 credits (configurable)
        // 1. Spend points
        // 2. Add credits via CreditService
    }
}
```

### Step 2.3: Create RewardService

```bash
touch app/Services/Loyalty/RewardService.php
```

**File to Create:**
- [ ] `app/Services/Loyalty/RewardService.php`

**Methods to Implement:**

```php
namespace App\Services\Loyalty;

class RewardService
{
    /**
     * Check if user can redeem reward
     */
    public function canRedeem(User $user, LoyaltyReward $reward): array
    {
        // 1. Check if user has active membership for this loyalty card
        // 2. Check if user's tier meets required_tier
        // 3. Check if reward is active and available
        // 4. Check if max_redemptions not reached
        // 5. Check if user already redeemed this reward
        // 6. Return eligibility status
    }

    /**
     * Redeem reward
     */
    public function redeem(User $user, LoyaltyReward $reward): LoyaltyRewardRedemption
    {
        // 1. Validate eligibility (call canRedeem)
        // 2. Create redemption record
        // 3. Increment current_redemptions on reward
        // 4. Fulfill reward based on type:
        //    - content: Generate download link
        //    - discount: Generate coupon code
        //    - experience: Send confirmation email
        //    - points: Award points via LoyaltyPointsService
        // 5. Send notification to user
        // 6. Return redemption record
    }

    /**
     * Fulfill reward (called after redemption)
     */
    protected function fulfillReward(LoyaltyRewardRedemption $redemption): void
    {
        // Handle different reward types:
        // - content: Copy file to user's library
        // - merchandise: Create store order with discount
        // - experience: Add to user's calendar
        // - points: Already handled in redeem()
    }

    /**
     * Get available rewards for user
     */
    public function getAvailableRewards(User $user, LoyaltyCard $loyaltyCard)
    {
        // 1. Get user's membership
        // 2. Filter rewards by tier eligibility
        // 3. Filter out already redeemed (if one-time)
        // 4. Filter by active status and availability dates
        // 5. Return collection
    }
}
```

### Step 2.4: Implement Middleware Logic

**File to Update:**
- [ ] `app/Http/Middleware/CheckLoyaltyTierAccess.php`

```php
public function handle(Request $request, Closure $next, string ...$tiers): Response
{
    $user = $request->user();
    
    if (!$user) {
        return redirect()->route('login');
    }
    
    // Get user's active memberships
    $userTiers = $user->loyaltyCardMemberships()
        ->where('status', 'active')
        ->pluck('tier')
        ->toArray();
    
    // Check if user has any of the required tiers
    $hasAccess = !empty(array_intersect($userTiers, $tiers));
    
    if (!$hasAccess) {
        abort(403, 'This feature requires a loyalty membership.');
    }
    
    return $next($request);
}
```

### Step 2.5: Implement Observer Logic

**Files to Update:**
- [ ] `app/Observers/Loyalty/LoyaltyCardObserver.php`
- [ ] `app/Observers/Loyalty/LoyaltyCardMemberObserver.php`
- [ ] `app/Observers/Loyalty/LoyaltyRewardObserver.php`

```php
// LoyaltyCardObserver.php
public function created(LoyaltyCard $card): void
{
    // Generate unique slug if not provided
    // Send notification to admin for approval
}

public function updated(LoyaltyCard $card): void
{
    // If published, send notification to artist's followers
}

// LoyaltyCardMemberObserver.php
public function created(LoyaltyCardMember $member): void
{
    // Increment total_members on loyalty_card
    // Send welcome email to member
    // Award "Joined Fan Club" badge/achievement
    // Create loyalty_points record if doesn't exist
}

public function updated(LoyaltyCardMember $member): void
{
    // If status changed to 'expired', send renewal reminder
    // If renewed, update stats (total_renewals, lifetime_value)
}

public function deleted(LoyaltyCardMember $member): void
{
    // Decrement total_members on loyalty_card
    // Send cancellation confirmation email
}
```

### Step 2.6: Implement Listener Logic

**Files to Update:**
- [ ] `app/Listeners/AwardEventLoyaltyPoints.php`
- [ ] `app/Listeners/AwardStoreLoyaltyPoints.php`

```php
// AwardEventLoyaltyPoints.php
public function handleTicketPurchased(TicketPurchased $event): void
{
    $attendee = $event->attendee;
    $user = $attendee->user;
    
    // Calculate base points (e.g., 1 point per 1000 UGX spent)
    $basePoints = ceil($attendee->price_paid_ugx / 1000);
    
    // Award points via service
    app(LoyaltyPointsService::class)->awardPoints(
        $user,
        $basePoints,
        'event_attendance',
        $attendee->event_id
    );
}

public function handleAttendeeCheckedIn(AttendeeCheckedIn $event): void
{
    $attendee = $event->attendee;
    $user = $attendee->user;
    
    // Award bonus points for actually attending
    app(LoyaltyPointsService::class)->awardPoints(
        $user,
        10, // Fixed 10 points for attending
        'event_attendance',
        $attendee->event_id
    );
}

// AwardStoreLoyaltyPoints.php
public function handleOrderPaid(OrderPaid $event): void
{
    $order = $event->order;
    $user = $order->user;
    
    // Award 1 point per 100 UGX spent
    $basePoints = ceil($order->total_ugx / 100);
    
    app(LoyaltyPointsService::class)->awardPoints(
        $user,
        $basePoints,
        'purchase',
        $order->id
    );
}
```

---

## Phase 3: Artist API Endpoints (Week 5-6)

### Step 3.1: Create Controllers

```bash
php artisan make:controller Api/Artist/LoyaltyCardController --api
php artisan make:controller Api/Artist/LoyaltyRewardController --api
```

**Files to Create:**
- [ ] `app/Http/Controllers/Api/Artist/LoyaltyCardController.php`
- [ ] `app/Http/Controllers/Api/Artist/LoyaltyRewardController.php`

### Step 3.2: Create Request Validators

```bash
php artisan make:request Loyalty/CreateLoyaltyCardRequest
php artisan make:request Loyalty/UpdateLoyaltyCardRequest
php artisan make:request Loyalty/CreateRewardRequest
```

**Files to Create:**
- [ ] `app/Http/Requests/Loyalty/CreateLoyaltyCardRequest.php`
- [ ] `app/Http/Requests/Loyalty/UpdateLoyaltyCardRequest.php`
- [ ] `app/Http/Requests/Loyalty/CreateRewardRequest.php`

**Validation Rules:**

```php
// CreateLoyaltyCardRequest.php
public function rules(): array
{
    return [
        'name' => 'required|string|max:255',
        'description' => 'required|string|max:2000',
        'logo' => 'nullable|image|max:2048',
        'banner' => 'nullable|image|max:5120',
        'primary_color' => 'required|regex:/^#[0-9A-F]{6}$/i',
        'secondary_color' => 'required|regex:/^#[0-9A-F]{6}$/i',
        'tiers' => 'required|array',
        'tiers.*.name' => 'required|string',
        'tiers.*.price_monthly' => 'required|integer|min:1000',
        'tiers.*.price_yearly' => 'required|integer|min:10000',
        'tiers.*.benefits' => 'required|array',
        'allow_monthly' => 'boolean',
        'allow_yearly' => 'boolean',
    ];
}
```

### Step 3.3: Implement Controller Methods

**LoyaltyCardController Methods:**
- [ ] `index()` - Get artist's loyalty cards
- [ ] `store(CreateLoyaltyCardRequest $request)` - Create card
- [ ] `show($id)` - Get card detail
- [ ] `update(UpdateLoyaltyCardRequest $request, $id)` - Update card
- [ ] `destroy($id)` - Archive card
- [ ] `publish($id)` - Publish draft card
- [ ] `members($id)` - Get members list
- [ ] `analytics($id)` - Get analytics

**LoyaltyRewardController Methods:**
- [ ] `index($loyaltyCardId)` - Get rewards
- [ ] `store($loyaltyCardId, CreateRewardRequest $request)` - Create reward
- [ ] `update($id, Request $request)` - Update reward
- [ ] `destroy($id)` - Delete reward

### Step 3.4: Create API Resources

```bash
php artisan make:resource Loyalty/LoyaltyCardResource
php artisan make:resource Loyalty/LoyaltyCardMemberResource
php artisan make:resource Loyalty/LoyaltyRewardResource
```

**Files to Create:**
- [ ] `app/Http/Resources/Loyalty/LoyaltyCardResource.php`
- [ ] `app/Http/Resources/Loyalty/LoyaltyCardMemberResource.php`
- [ ] `app/Http/Resources/Loyalty/LoyaltyRewardResource.php`

### Step 3.5: Define Routes

**File to Create:**
- [ ] `routes/api/loyalty.php`

```php
use App\Http\Controllers\Api\Artist\LoyaltyCardController;
use App\Http\Controllers\Api\Artist\LoyaltyRewardController;

// Artist routes
Route::middleware(['auth:sanctum', 'role:artist'])->prefix('artist')->group(function () {
    Route::apiResource('loyalty-cards', LoyaltyCardController::class);
    Route::patch('loyalty-cards/{id}/publish', [LoyaltyCardController::class, 'publish']);
    Route::get('loyalty-cards/{id}/members', [LoyaltyCardController::class, 'members']);
    Route::get('loyalty-cards/{id}/analytics', [LoyaltyCardController::class, 'analytics']);
    
    Route::prefix('loyalty-cards/{loyaltyCardId}')->group(function () {
        Route::apiResource('rewards', LoyaltyRewardController::class);
    });
});
```

**Update:**
- [ ] `routes/api.php` - Add `require __DIR__ . '/api/loyalty.php';`

---

## Phase 4: Fan API Endpoints (Week 7-8)

### Step 4.1: Create Controllers

```bash
php artisan make:controller Api/LoyaltyController
php artisan make:controller Api/MembershipController
php artisan make:controller Api/LoyaltyPointsController
```

**Files to Create:**
- [ ] `app/Http/Controllers/Api/LoyaltyController.php`
- [ ] `app/Http/Controllers/Api/MembershipController.php`
- [ ] `app/Http/Controllers/Api/LoyaltyPointsController.php`

### Step 4.2: Implement Payment Integration

**Create Service:**
- [ ] `app/Services/Loyalty/MembershipPaymentService.php`

```php
class MembershipPaymentService
{
    public function subscribe(User $user, LoyaltyCard $card, string $tier, string $subscriptionType, array $paymentData)
    {
        // 1. Get tier pricing from card->tiers JSON
        // 2. Calculate price based on subscription_type (monthly/yearly)
        // 3. Process payment via PaymentService (mobile money, credits, hybrid)
        // 4. Create LoyaltyCardMember record (status: pending)
        // 5. On payment success, update status to 'active'
        // 6. Set expires_at (30 days for monthly, 365 for yearly)
        // 7. Send welcome email
        // 8. Return membership + payment response
    }
}
```

### Step 4.3: Controller Methods

**LoyaltyController:**
- [ ] `index()` - Browse all loyalty cards (public)
- [ ] `show($slug)` - Get loyalty card detail (public)
- [ ] `join($slug, JoinLoyaltyCardRequest $request)` - Subscribe (auth)

**MembershipController:**
- [ ] `index()` - Get my memberships
- [ ] `show($id)` - Get membership detail
- [ ] `update($id, Request $request)` - Update settings (auto_renew)
- [ ] `cancel($id)` - Cancel membership
- [ ] `renew($id)` - Manually renew

**LoyaltyPointsController:**
- [ ] `show()` - Get my points balance
- [ ] `transactions()` - Get transaction history
- [ ] `convert(Request $request)` - Convert points to credits

### Step 4.4: Reward Redemption Endpoints

**Add to LoyaltyController:**
- [ ] `availableRewards($loyaltyCardId)` - Get rewards for my tier
- [ ] `redeemReward($rewardId, Request $request)` - Redeem reward

### Step 4.5: Define Routes

**Update `routes/api/loyalty.php`:**

```php
// Public routes
Route::get('loyalty-cards', [LoyaltyController::class, 'index']);
Route::get('loyalty-cards/{slug}', [LoyaltyController::class, 'show']);

// Authenticated fan routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('loyalty-cards/{slug}/join', [LoyaltyController::class, 'join']);
    
    Route::prefix('my')->group(function () {
        Route::get('memberships', [MembershipController::class, 'index']);
        Route::get('memberships/{id}', [MembershipController::class, 'show']);
        Route::patch('memberships/{id}', [MembershipController::class, 'update']);
        Route::post('memberships/{id}/cancel', [MembershipController::class, 'cancel']);
        Route::post('memberships/{id}/renew', [MembershipController::class, 'renew']);
        
        Route::get('loyalty-points', [LoyaltyPointsController::class, 'show']);
        Route::get('loyalty-points/transactions', [LoyaltyPointsController::class, 'transactions']);
        Route::post('loyalty-points/convert', [LoyaltyPointsController::class, 'convert']);
    });
    
    Route::get('loyalty-cards/{id}/rewards', [LoyaltyController::class, 'availableRewards']);
    Route::post('loyalty-rewards/{id}/redeem', [LoyaltyController::class, 'redeemReward']);
});
```

---

## Phase 5: Next.js Frontend - Artist UI (Week 9-10)

### Step 5.1: Set Up Next.js Structure

```bash
# Assuming Next.js project exists in separate directory
cd /path/to/nextjs-app

# Create directories
mkdir -p app/(dashboard)/artist/loyalty
mkdir -p components/loyalty
mkdir -p lib/api/loyalty
mkdir -p types/loyalty
```

**Files to Create:**
- [ ] `types/loyalty.ts` - TypeScript interfaces
- [ ] `lib/api/loyalty.ts` - API client functions
- [ ] `components/loyalty/` - Reusable components

### Step 5.2: Create TypeScript Types

**File:** `types/loyalty.ts`

```typescript
export interface LoyaltyCard {
  id: number;
  uuid: string;
  name: string;
  slug: string;
  description: string;
  logo_url: string | null;
  banner_url: string | null;
  primary_color: string;
  secondary_color: string;
  tiers: Record<string, TierConfig>;
  status: 'draft' | 'active' | 'paused' | 'archived';
  total_members: number;
  monthly_revenue: number;
  artist: Artist;
}

export interface TierConfig {
  name: string;
  price_monthly: number;
  price_yearly: number;
  benefits: TierBenefits;
}

export interface TierBenefits {
  event_discount_percentage: number;
  early_access_hours: number;
  exclusive_content: boolean;
  store_discount_percentage: number;
  loyalty_points_multiplier: number;
  priority_support?: boolean;
  meet_and_greet?: boolean;
  badge_icon: string;
}

export interface LoyaltyCardMember {
  id: number;
  loyalty_card_id: number;
  user_id: number;
  tier: string;
  subscription_type: 'monthly' | 'yearly';
  price_paid: number;
  status: 'active' | 'expired' | 'cancelled' | 'pending';
  joined_at: string;
  expires_at: string;
  auto_renew: boolean;
  loyalty_card: LoyaltyCard;
  user: User;
}

export interface LoyaltyReward {
  id: number;
  loyalty_card_id: number;
  name: string;
  description: string;
  type: 'content' | 'merchandise' | 'experience' | 'discount' | 'points';
  required_tier: string;
  is_active: boolean;
  max_redemptions: number | null;
  current_redemptions: number;
}
```

### Step 5.3: Create API Client Functions

**File:** `lib/api/loyalty.ts`

```typescript
import { apiClient } from './client'; // Axios instance with auth

export const loyaltyApi = {
  // Artist endpoints
  getMyCards: () => apiClient.get('/artist/loyalty-cards'),
  createCard: (data: CreateCardData) => apiClient.post('/artist/loyalty-cards', data),
  updateCard: (id: number, data: UpdateCardData) => apiClient.put(`/artist/loyalty-cards/${id}`, data),
  publishCard: (id: number) => apiClient.patch(`/artist/loyalty-cards/${id}/publish`),
  getCardMembers: (id: number) => apiClient.get(`/artist/loyalty-cards/${id}/members`),
  getCardAnalytics: (id: number) => apiClient.get(`/artist/loyalty-cards/${id}/analytics`),
  
  // Rewards
  getRewards: (cardId: number) => apiClient.get(`/artist/loyalty-cards/${cardId}/rewards`),
  createReward: (cardId: number, data: CreateRewardData) => apiClient.post(`/artist/loyalty-cards/${cardId}/rewards`, data),
};
```

### Step 5.4: Create Artist Pages

**Files to Create:**
- [ ] `app/(dashboard)/artist/loyalty/page.tsx` - Dashboard
- [ ] `app/(dashboard)/artist/loyalty/create/page.tsx` - Create card
- [ ] `app/(dashboard)/artist/loyalty/[id]/page.tsx` - Card detail
- [ ] `app/(dashboard)/artist/loyalty/[id]/edit/page.tsx` - Edit card
- [ ] `app/(dashboard)/artist/loyalty/[id]/members/page.tsx` - Members list
- [ ] `app/(dashboard)/artist/loyalty/[id]/analytics/page.tsx` - Analytics
- [ ] `app/(dashboard)/artist/loyalty/[id]/rewards/page.tsx` - Rewards management

### Step 5.5: Create Components

**Files to Create:**
- [ ] `components/loyalty/LoyaltyCardForm.tsx` - Multi-step form for create/edit
- [ ] `components/loyalty/TierConfigCard.tsx` - Configure tier pricing/benefits
- [ ] `components/loyalty/MembersTable.tsx` - Members list with filters
- [ ] `components/loyalty/AnalyticsDashboard.tsx` - Charts and stats
- [ ] `components/loyalty/RewardsList.tsx` - Rewards CRUD interface

**Example Component:**

```tsx
// components/loyalty/TierConfigCard.tsx
'use client';

import { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';

interface TierConfigCardProps {
  tier: string;
  config: TierConfig;
  onChange: (config: TierConfig) => void;
}

export function TierConfigCard({ tier, config, onChange }: TierConfigCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg capitalize">{tier} Tier</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div>
          <label>Tier Name</label>
          <Input
            value={config.name}
            onChange={(e) => onChange({ ...config, name: e.target.value })}
          />
        </div>
        
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label>Monthly Price (UGX)</label>
            <Input
              type="number"
              value={config.price_monthly}
              onChange={(e) => onChange({ ...config, price_monthly: Number(e.target.value) })}
            />
          </div>
          
          <div>
            <label>Yearly Price (UGX)</label>
            <Input
              type="number"
              value={config.price_yearly}
              onChange={(e) => onChange({ ...config, price_yearly: Number(e.target.value) })}
            />
          </div>
        </div>
        
        <div className="space-y-2">
          <h4 className="font-medium">Benefits</h4>
          
          <div>
            <label>Event Discount (%)</label>
            <Input
              type="number"
              min="0"
              max="100"
              value={config.benefits.event_discount_percentage}
              onChange={(e) => onChange({
                ...config,
                benefits: { ...config.benefits, event_discount_percentage: Number(e.target.value) }
              })}
            />
          </div>
          
          <div>
            <label>Early Access (hours)</label>
            <Input
              type="number"
              min="0"
              value={config.benefits.early_access_hours}
              onChange={(e) => onChange({
                ...config,
                benefits: { ...config.benefits, early_access_hours: Number(e.target.value) }
              })}
            />
          </div>
          
          <div className="flex items-center space-x-2">
            <Checkbox
              checked={config.benefits.exclusive_content}
              onCheckedChange={(checked) => onChange({
                ...config,
                benefits: { ...config.benefits, exclusive_content: !!checked }
              })}
            />
            <label>Exclusive Content Access</label>
          </div>
          
          {/* Add more benefit checkboxes */}
        </div>
      </CardContent>
    </Card>
  );
}
```

---

## Phase 6: Next.js Frontend - Fan UI (Week 11-12)

### Step 6.1: Create Fan Pages

**Files to Create:**
- [ ] `app/loyalty/page.tsx` - Browse loyalty cards
- [ ] `app/loyalty/[slug]/page.tsx` - Loyalty card detail
- [ ] `app/loyalty/[slug]/join/page.tsx` - Join/subscribe flow
- [ ] `app/(dashboard)/memberships/page.tsx` - My memberships
- [ ] `app/(dashboard)/memberships/[id]/page.tsx` - Membership detail
- [ ] `app/(dashboard)/loyalty-points/page.tsx` - Points dashboard

### Step 6.2: Create Fan Components

**Files to Create:**
- [ ] `components/loyalty/LoyaltyCardGrid.tsx` - Browse view
- [ ] `components/loyalty/LoyaltyCardPreview.tsx` - Card preview
- [ ] `components/loyalty/TierPricingCard.tsx` - Tier selection
- [ ] `components/loyalty/MembershipCard.tsx` - My membership card
- [ ] `components/loyalty/RewardCard.tsx` - Reward display
- [ ] `components/loyalty/LoyaltyPointsWidget.tsx` - Points balance

**Example Component:**

```tsx
// components/loyalty/TierPricingCard.tsx
'use client';

import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Check } from 'lucide-react';

interface TierPricingCardProps {
  tier: string;
  config: TierConfig;
  selected: boolean;
  subscriptionType: 'monthly' | 'yearly';
  onSelect: () => void;
}

export function TierPricingCard({ tier, config, selected, subscriptionType, onSelect }: TierPricingCardProps) {
  const price = subscriptionType === 'monthly' ? config.price_monthly : config.price_yearly;
  const pricePerMonth = subscriptionType === 'yearly' ? price / 12 : price;
  
  return (
    <Card className={`relative ${selected ? 'ring-2 ring-primary' : ''}`}>
      {tier === 'gold' && (
        <Badge className="absolute -top-3 left-1/2 -translate-x-1/2">Most Popular</Badge>
      )}
      
      <CardHeader>
        <div className="flex items-center justify-between">
          <CardTitle className="text-xl capitalize">{config.name}</CardTitle>
          <img src={`/icons/${config.benefits.badge_icon}`} alt={tier} className="w-8 h-8" />
        </div>
        
        <div className="mt-4">
          <span className="text-3xl font-bold">{price.toLocaleString()}</span>
          <span className="text-muted-foreground"> UGX</span>
          {subscriptionType === 'yearly' && (
            <p className="text-sm text-muted-foreground">
              {pricePerMonth.toLocaleString()} UGX/month
            </p>
          )}
        </div>
      </CardHeader>
      
      <CardContent className="space-y-4">
        <Button
          onClick={onSelect}
          className="w-full"
          variant={selected ? 'default' : 'outline'}
        >
          {selected ? 'Selected' : 'Select Plan'}
        </Button>
        
        <ul className="space-y-2">
          {config.benefits.event_discount_percentage > 0 && (
            <li className="flex items-start gap-2">
              <Check className="w-5 h-5 text-primary mt-0.5" />
              <span>{config.benefits.event_discount_percentage}% off event tickets</span>
            </li>
          )}
          
          {config.benefits.early_access_hours > 0 && (
            <li className="flex items-start gap-2">
              <Check className="w-5 h-5 text-primary mt-0.5" />
              <span>{config.benefits.early_access_hours} hours early access</span>
            </li>
          )}
          
          {config.benefits.exclusive_content && (
            <li className="flex items-start gap-2">
              <Check className="w-5 h-5 text-primary mt-0.5" />
              <span>Exclusive unreleased content</span>
            </li>
          )}
          
          {config.benefits.loyalty_points_multiplier > 1 && (
            <li className="flex items-start gap-2">
              <Check className="w-5 h-5 text-primary mt-0.5" />
              <span>{config.benefits.loyalty_points_multiplier}× loyalty points</span>
            </li>
          )}
        </ul>
      </CardContent>
    </Card>
  );
}
```

### Step 6.3: Implement Join Flow

**File:** `app/loyalty/[slug]/join/page.tsx`

```tsx
'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { TierPricingCard } from '@/components/loyalty/TierPricingCard';
import { PaymentMethodSelector } from '@/components/payment/PaymentMethodSelector';
import { loyaltyApi } from '@/lib/api/loyalty';

export default function JoinLoyaltyPage({ params }: { params: { slug: string } }) {
  const router = useRouter();
  const [selectedTier, setSelectedTier] = useState<string>('bronze');
  const [subscriptionType, setSubscriptionType] = useState<'monthly' | 'yearly'>('monthly');
  const [paymentMethod, setPaymentMethod] = useState<string>('mobile_money');
  const [isLoading, setIsLoading] = useState(false);
  
  // TODO: Fetch loyalty card data
  
  const handleJoin = async () => {
    setIsLoading(true);
    try {
      const response = await loyaltyApi.joinCard(params.slug, {
        tier: selectedTier,
        subscription_type: subscriptionType,
        payment_method: paymentMethod,
        auto_renew: true,
      });
      
      // Handle payment redirect or confirmation
      if (response.data.payment?.payment_url) {
        window.location.href = response.data.payment.payment_url;
      } else {
        router.push('/dashboard/memberships');
      }
    } catch (error) {
      console.error('Join failed:', error);
    } finally {
      setIsLoading(false);
    }
  };
  
  return (
    <div className="container max-w-6xl py-10">
      {/* Step 1: Select Tier */}
      {/* Step 2: Select Subscription Type (monthly/yearly) */}
      {/* Step 3: Select Payment Method */}
      {/* Step 4: Confirm & Pay */}
    </div>
  );
}
```

---

## Phase 7: Admin, Jobs & Polish (Week 13-14)

### Step 7.1: Create Admin Controller

```bash
php artisan make:controller Admin/LoyaltyController
```

**File to Create:**
- [ ] `app/Http/Controllers/Admin/LoyaltyController.php`

**Methods:**
- [ ] `index()` - All loyalty cards
- [ ] `show($id)` - Card detail
- [ ] `approve($id)` - Approve pending card
- [ ] `flag($id)` - Flag for review
- [ ] `ban($id)` - Ban card
- [ ] `analytics()` - Platform-wide analytics

### Step 7.2: Create Cron Job for Renewals

```bash
php artisan make:command Loyalty/ProcessRenewals
```

**File to Create:**
- [ ] `app/Console/Commands/Loyalty/ProcessRenewals.php`

```php
public function handle()
{
    $expiring = LoyaltyCardMember::where('status', 'active')
        ->where('auto_renew', true)
        ->where('expires_at', '<=', now())
        ->get();
    
    foreach ($expiring as $membership) {
        try {
            // Attempt renewal via MembershipPaymentService
            $result = app(MembershipPaymentService::class)->renew($membership);
            
            if ($result['success']) {
                $this->info("Renewed membership #{$membership->id}");
            } else {
                // Mark as expired, send email
                $membership->update(['status' => 'expired']);
                $this->warn("Failed to renew membership #{$membership->id}");
            }
        } catch (\Exception $e) {
            $this->error("Error renewing #{$membership->id}: {$e->getMessage()}");
        }
    }
}
```

**Schedule in `app/Console/Kernel.php`:**

```php
$schedule->command('loyalty:process-renewals')->daily();
```

### Step 7.3: Create Notification for Expiry

```bash
php artisan make:notification Loyalty/MembershipExpiringNotification
php artisan make:notification Loyalty/MembershipExpiredNotification
```

### Step 7.4: Add Real-Time Updates (Pusher)

**Install Pusher:**
```bash
composer require pusher/pusher-php-server
npm install pusher-js
```

**Broadcast when membership status changes:**

```php
// In LoyaltyCardMemberObserver.php
public function updated(LoyaltyCardMember $member): void
{
    if ($member->isDirty('status')) {
        broadcast(new MembershipStatusChanged($member))->toOthers();
    }
}
```

### Step 7.5: Create Admin Routes

**File to Create:**
- [ ] `routes/admin/loyalty.php`

```php
use App\Http\Controllers\Admin\LoyaltyController;

Route::middleware(['auth', 'role:admin'])->prefix('admin/loyalty')->name('admin.loyalty.')->group(function () {
    Route::get('/', [LoyaltyController::class, 'index'])->name('index');
    Route::get('/{id}', [LoyaltyController::class, 'show'])->name('show');
    Route::patch('/{id}/approve', [LoyaltyController::class, 'approve'])->name('approve');
    Route::patch('/{id}/flag', [LoyaltyController::class, 'flag'])->name('flag');
    Route::patch('/{id}/ban', [LoyaltyController::class, 'ban'])->name('ban');
    Route::get('/analytics', [LoyaltyController::class, 'analytics'])->name('analytics');
});
```

### Step 7.6: Create Tests

```bash
php artisan make:test Loyalty/LoyaltyCardTest
php artisan make:test Loyalty/MembershipTest
php artisan make:test Loyalty/TierAccessTest
```

**Files to Create:**
- [ ] `tests/Feature/Loyalty/LoyaltyCardTest.php`
- [ ] `tests/Feature/Loyalty/MembershipTest.php`
- [ ] `tests/Feature/Loyalty/TierAccessTest.php`

**Example Test:**

```php
// tests/Feature/Loyalty/MembershipTest.php
public function test_fan_can_join_loyalty_card()
{
    $artist = User::factory()->artist()->create();
    $card = LoyaltyCard::factory()->create(['artist_id' => $artist->id]);
    $fan = User::factory()->create();
    
    $response = $this->actingAs($fan)
        ->postJson("/api/loyalty-cards/{$card->slug}/join", [
            'tier' => 'bronze',
            'subscription_type' => 'monthly',
            'payment_method' => 'credits',
        ]);
    
    $response->assertStatus(201);
    $this->assertDatabaseHas('loyalty_card_members', [
        'loyalty_card_id' => $card->id,
        'user_id' => $fan->id,
        'tier' => 'bronze',
    ]);
}
```

### Step 7.7: Performance Optimization

- [ ] Add database indexes (see migrations)
- [ ] Cache loyalty card data (Redis): `Cache::remember("loyalty_card_{$slug}", 3600, ...)`
- [ ] Eager load relationships in queries
- [ ] Optimize tier access checks (cache user's memberships)

### Step 7.8: Deploy to Production

```bash
# Run migrations
php artisan migrate --force

# Seed data (optional)
php artisan db:seed --class=LoyaltySeeder

# Clear caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue workers
php artisan queue:restart
```

---

## Testing Checklist

### Manual Testing Scenarios

- [ ] **Artist creates loyalty card**
  1. Navigate to `/artist/loyalty/create`
  2. Fill form with 3 tiers (Bronze, Silver, Gold)
  3. Upload logo and banner
  4. Submit and verify card appears in dashboard

- [ ] **Fan joins loyalty card**
  1. Browse cards at `/loyalty`
  2. Click on card → View detail
  3. Click "Join" → Select Gold tier, monthly subscription
  4. Complete mobile money payment
  5. Verify membership appears in `/dashboard/memberships`

- [ ] **Tier access to event**
  1. Artist creates event with "Silver tier required"
  2. Non-member tries to access → Should see "Membership required" message
  3. Silver member accesses → Should see event with 10% discount
  4. Bronze member tries to access → Should see "Upgrade to Silver" message

- [ ] **Reward redemption**
  1. Gold member views available rewards
  2. Clicks "Redeem" on exclusive track
  3. Receives download link via email
  4. Reward shows as "Redeemed" in dashboard

- [ ] **Loyalty points earning**
  1. Member streams a song → Check points increased (with multiplier)
  2. Member purchases store item → Check points increased
  3. View points transaction history

- [ ] **Membership renewal**
  1. Wait for membership to expire (or manually set expires_at to past)
  2. Run `php artisan loyalty:process-renewals`
  3. Verify membership renewed or marked expired
  4. Check renewal email sent

### Automated Tests to Run

```bash
# Unit tests
php artisan test --testsuite=Unit --filter=Loyalty

# Feature tests
php artisan test --testsuite=Feature --filter=Loyalty

# All tests
php artisan test
```

---

## Configuration File

**Create:** `config/loyalty.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform Commission
    |--------------------------------------------------------------------------
    |
    | Percentage of subscription revenue the platform takes
    |
    */
    'platform_commission_percentage' => env('LOYALTY_COMMISSION', 10),

    /*
    |--------------------------------------------------------------------------
    | Tier Levels
    |--------------------------------------------------------------------------
    |
    | Numeric levels for tier comparison (higher = better)
    |
    */
    'tier_levels' => [
        'bronze' => 1,
        'silver' => 2,
        'gold' => 3,
        'platinum' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Points Conversion
    |--------------------------------------------------------------------------
    |
    | How many points equal 1 credit
    |
    */
    'points_to_credits_rate' => 10, // 100 points = 10 credits

    /*
    |--------------------------------------------------------------------------
    | Points Earning Rules
    |--------------------------------------------------------------------------
    |
    | Base points awarded for various actions (before multiplier)
    |
    */
    'points_earning' => [
        'stream' => 1,
        'download' => 5,
        'purchase_per_100_ugx' => 1,
        'event_attendance' => 10,
        'referral' => 50,
        'daily_login' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Renewal Settings
    |--------------------------------------------------------------------------
    */
    'renewal_reminder_days' => 3, // Send reminder 3 days before expiry
    'grace_period_days' => 7, // Allow renewal within 7 days after expiry

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    */
    'payment_methods' => ['mobile_money', 'credits', 'hybrid'],

    /*
    |--------------------------------------------------------------------------
    | Admin Approval Required
    |--------------------------------------------------------------------------
    |
    | Whether new loyalty cards need admin approval before going live
    |
    */
    'requires_admin_approval' => true,
];
```

---

## Environment Variables

Add to `.env`:

```env
# Loyalty Configuration
LOYALTY_COMMISSION=10
LOYALTY_REQUIRES_APPROVAL=true

# Pusher (for real-time updates)
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_key
PUSHER_APP_SECRET=your_secret
PUSHER_APP_CLUSTER=mt1
```

---

## Documentation to Create

- [ ] **API Documentation** (Swagger/OpenAPI)
- [ ] **Artist Guide** - "How to Create Your Fan Club"
- [ ] **Fan Guide** - "How to Join and Use Loyalty Memberships"
- [ ] **Developer Guide** - Code architecture and extension points
- [ ] **Admin Guide** - Moderation and approval workflow

---

## Post-Implementation Tasks

- [ ] Monitor error logs for first 48 hours
- [ ] Track KPIs (sign-ups, renewals, churn rate)
- [ ] Gather user feedback (surveys, interviews)
- [ ] Identify bottlenecks and optimize
- [ ] Plan Phase 2 features (NFT memberships, family plans, etc.)

---

## Success Metrics to Track

- **Loyalty Cards Created**: Target 50 in first month
- **Total Memberships**: Target 1,000 in first quarter
- **MRR (Monthly Recurring Revenue)**: Target 10M UGX (~$2,700)
- **Churn Rate**: Target <8% monthly
- **Renewal Rate**: Target >90%
- **Average LTV per Member**: Target 240,000 UGX (~$65)

---

## Support & Resources

- **Full Audit Document**: See `LOYALTY_SYSTEM_AUDIT_AND_REBUILD.md`
- **Laravel Docs**: https://laravel.com/docs
- **Next.js Docs**: https://nextjs.org/docs
- **Shadcn UI**: https://ui.shadcn.com
- **Pusher Docs**: https://pusher.com/docs

---

## Final Checklist Before Production

- [ ] All migrations run successfully
- [ ] All tests passing (100% critical paths)
- [ ] API documentation published
- [ ] Admin dashboard functional
- [ ] Payment flows tested end-to-end (sandbox)
- [ ] Email templates reviewed
- [ ] Real-time notifications working
- [ ] Cron jobs scheduled
- [ ] Error monitoring set up (Sentry)
- [ ] Performance benchmarked (< 200ms avg API response)
- [ ] Security audit completed
- [ ] User documentation published
- [ ] Support team trained
- [ ] Rollback plan prepared

---

**IMPLEMENTATION STATUS TRACKER**

| Phase | Status | Start Date | End Date | Notes |
|-------|--------|------------|----------|-------|
| 1: Database & Models | 🟡 Not Started | | | |
| 2: Services & Logic | 🟡 Not Started | | | |
| 3: Artist API | 🟡 Not Started | | | |
| 4: Fan API | 🟡 Not Started | | | |
| 5: Artist UI (Next.js) | 🟡 Not Started | | | |
| 6: Fan UI (Next.js) | 🟡 Not Started | | | |
| 7: Admin & Polish | 🟡 Not Started | | | |

Legend:
- 🟡 Not Started
- 🔵 In Progress
- 🟢 Completed
- 🔴 Blocked

---

**READY FOR COPILOT IMPLEMENTATION**

This checklist provides all necessary code snippets, file structures, and step-by-step instructions for GitHub Copilot to implement the Loyalty System. Start with Phase 1 and proceed sequentially.

For detailed business logic, refer to `LOYALTY_SYSTEM_AUDIT_AND_REBUILD.md`.