# TesoTunes Loyalty System - Complete Audit & Next.js Rebuild Prompt

## Executive Summary

The **Loyalty System** (also referred to as "Artist Fan Clubs" or "Tier-Based Membership") is a **PARTIALLY IMPLEMENTED** feature in TesoTunes. The infrastructure is **scaffolded** but not fully built. This audit reveals that while the concept is integrated into Events, the core loyalty models, controllers, services, and UI are **missing or incomplete**.

This document provides a complete audit of the current state and a comprehensive rebuild prompt for implementing the Loyalty System with a modern Next.js frontend and Laravel API backend.

---

## 1. Current Implementation Status - Detailed Audit

### 1.1 What EXISTS (Scaffolded/Placeholder)

#### **Models & Relationships** (Referenced but NOT Created)
The following models are **referenced** in code but **DO NOT EXIST** as files:

- ❌ `App\Models\Loyalty\LoyaltyCard` - Referenced in Artist, Event models
- ❌ `App\Models\Loyalty\LoyaltyCardMember` - Referenced in User, EventTicket
- ❌ `App\Models\Loyalty\LoyaltyReward` - Referenced in observers
- ❌ `App\Models\LoyaltyPoints` - Referenced in User model
- ❌ `App\Services\Loyalty\TierAccessService` - Called extensively in Event/EventTicket

**Evidence:**
```php
// app/Models/User.php (Lines 268-280)
public function loyaltyPoints(): HasOne
{
    return $this->hasOne(LoyaltyPoints::class); // Model doesn't exist
}

public function loyaltyCardMemberships(): HasMany
{
    return $this->hasMany(\App\Models\Loyalty\LoyaltyCardMember::class); // Model doesn't exist
}

// app/Models/Artist.php (Lines 177-183)
public function loyaltyCards(): HasMany
{
    return $this->hasMany(\App\Models\Loyalty\LoyaltyCard::class); // Model doesn't exist
}

// app/Models/Event.php (Lines 467-469)
public function loyaltyCard()
{
    return $this->belongsTo(\App\Models\Loyalty\LoyaltyCard::class, 'loyalty_card_id');
}
```

#### **Observers** (Exist but Empty)
- ✅ `app/Observers/Loyalty/LoyaltyCardObserver.php` - Created, but methods are empty stubs
- ✅ `app/Observers/Loyalty/LoyaltyCardMemberObserver.php` - Created, but methods are empty stubs
- ✅ `app/Observers/Loyalty/LoyaltyRewardObserver.php` - Created, but methods are empty stubs

```php
// All observer methods are empty placeholders:
public function created(LoyaltyCard $card): void
{
    //
}
```

#### **Listeners** (Exist but Empty)
- ✅ `app/Listeners/AwardEventLoyaltyPoints.php` - Created, but logic is NOT implemented
- ✅ `app/Listeners/AwardStoreLoyaltyPoints.php` - Created, but logic is NOT implemented

```php
// All listener methods are empty:
public function handleTicketPurchased(TicketPurchased $event): void
{
    //
}
```

#### **Middleware** (Exists but Non-Functional)
- ✅ `app/Http/Middleware/CheckLoyaltyTierAccess.php` - Created, but does NOT check anything

```php
// Current implementation just passes through:
public function handle(Request $request, Closure $next, string ...$tiers): Response
{
    return $next($request); // No actual check!
}
```

#### **Database Fields** (Exist in Events Module)
The `events` table has loyalty fields:
- ✅ `required_loyalty_tier` (string) - Bronze, Silver, Gold, Platinum, etc.
- ✅ `loyalty_card_id` (integer) - FK to loyalty_cards table (which doesn't exist)
- ✅ `tier_early_access_hours` (integer) - Hours of early access for tier members
- ✅ `hide_from_non_qualifying` (boolean) - Hide event from non-members

The `event_tickets` table has loyalty fields:
- ✅ `required_loyalty_tier` (string)
- ✅ `tier_early_access_hours` (integer)
- ✅ `tier_discounts` (JSON) - e.g., `{"bronze": 5, "silver": 10, "gold": 15}`

#### **Event Model Integration** (Exists but Calls Non-Existent Service)
- ✅ `Event::requiresLoyaltyTier()` - Checks if event has tier requirement
- ✅ `Event::userMeetsTierRequirement($user)` - Calls TierAccessService (doesn't exist)
- ✅ `Event::getTierAccessForUser($user)` - Calls TierAccessService
- ✅ `Event::scopeAccessibleByUser($query, $user)` - Filters by tier
- ✅ `Event::scopeTierRestricted($query)` - Filters tier-gated events

#### **EventTicket Model Integration** (Exists but Incomplete)
- ✅ `EventTicket::requiresLoyaltyTier()` - Checks if ticket requires tier
- ✅ `EventTicket::userCanPurchase($user)` - Calls TierAccessService (doesn't exist)
- ✅ `EventTicket::getPriceForUser($user)` - Returns discounted price for tier members
- ✅ `EventTicket::userHasEarlyAccess($user)` - Checks early access eligibility

#### **Routes** (Referenced but Files Don't Exist)
In `routes/admin.php`:
```php
require __DIR__ . '/admin/loyalty.php'; // FILE DOESN'T EXIST
```

In `routes/api.php`:
```php
// Loyalty (Artist Fan Clubs) API Routes
require __DIR__ . '/api/loyalty.php'; // FILE DOESN'T EXIST
```

In `routes/frontend.php`:
```php
require __DIR__ . '/frontend/loyalty.php'; // FILE DOESN'T EXIST
```

In `resources/views/frontend/artist/dashboard.blade.php`:
```php
<a href="{{ route('artist.loyalty.index') }}"> // ROUTE DOESN'T EXIST
```

#### **CSS** (Exists but Empty)
- ✅ `resources/css/components/loyalty-card.css` - File exists, but only has comment: `/* Loyalty Card Styles */`

---

### 1.2 What DOES NOT EXIST (Core Features Missing)

#### **Models** ❌
- `App\Models\Loyalty\LoyaltyCard` - Artist's loyalty program/fan club
- `App\Models\Loyalty\LoyaltyCardMember` - User's membership in a loyalty card
- `App\Models\Loyalty\LoyaltyReward` - Rewards for tier members
- `App\Models\Loyalty\LoyaltyTransaction` - Points transactions
- `App\Models\LoyaltyPoints` - Platform-wide loyalty points balance

#### **Services** ❌
- `App\Services\Loyalty\TierAccessService` - Access control and discount logic
- `App\Services\Loyalty\LoyaltyPointsService` - Points earning/spending
- `App\Services\Loyalty\RewardService` - Reward fulfillment

#### **Controllers** ❌
- `App\Http\Controllers\Frontend\Artist\LoyaltyController` - Artist manages their fan club
- `App\Http\Controllers\Frontend\LoyaltyController` - Fan joins/browses loyalty cards
- `App\Http\Controllers\Api\LoyaltyController` - API for loyalty features
- `App\Http\Controllers\Admin\LoyaltyController` - Admin manages loyalty cards

#### **Migrations** ❌
- `create_loyalty_cards_table`
- `create_loyalty_card_members_table`
- `create_loyalty_rewards_table`
- `create_loyalty_transactions_table`
- `create_loyalty_points_table`

#### **Routes** ❌
- No loyalty routes defined
- Routes are referenced but files don't exist

#### **Frontend/Blade Views** ❌
- No loyalty-related views exist
- Dashboard link points to non-existent route

#### **API Endpoints** ❌
- No API endpoints for loyalty features

#### **Tests** ❌
- No loyalty feature tests

#### **Seeders** ❌
- No loyalty data seeders

#### **Config** ❌
- No `config/loyalty.php` configuration file

---

## 2. The Loyalty System Concept - What It Should Be

### 2.1 Core Idea: Artist Fan Clubs with Tiered Membership

**"Artists create tiered membership programs (fan clubs) where fans pay monthly/yearly subscriptions to unlock exclusive perks"**

### 2.2 Key Features

#### **For Artists (Loyalty Card Creators)**
1. **Create Loyalty Programs**: Name, description, branding (logo, colors)
2. **Define Tiers**: Bronze, Silver, Gold, Platinum (customizable names)
3. **Set Pricing**: Monthly/yearly subscription fees per tier
4. **Configure Benefits**:
   - Event ticket discounts (10% off for Silver, 20% for Gold)
   - Early access to event tickets (24 hours before public)
   - Exclusive content (unreleased songs, behind-the-scenes videos)
   - Merchandise discounts (15% off store purchases)
   - Priority support (faster response to messages)
   - Meet & greets (VIP tier only)
   - Loyalty points multiplier (2x points for Gold members)

#### **For Fans (Loyalty Card Members)**
1. **Browse Artists' Loyalty Programs**: Discover fan clubs
2. **Join Tiers**: Select tier, pay monthly/yearly subscription
3. **Access Benefits**: Automatic application of discounts, early access, exclusive content
4. **Earn Points**: Loyalty points for purchases, streaming, engagement
5. **Upgrade/Downgrade**: Change tiers, cancel anytime
6. **Membership Dashboard**: View benefits, points balance, renewal date

#### **Platform Benefits**
1. **Recurring Revenue**: Platform takes 10-15% commission on subscriptions
2. **Increased Artist Revenue**: Predictable income from loyal fans
3. **Fan Retention**: Membership creates lock-in and long-term engagement
4. **Data Insights**: Track which benefits drive most memberships

---

## 3. System Architecture & Data Model

### 3.1 Database Schema

#### **loyalty_cards** (Artist's Loyalty Program)
```sql
CREATE TABLE loyalty_cards (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    artist_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL, -- "DJ Kiboko Fan Club"
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    logo_url VARCHAR(500),
    banner_url VARCHAR(500),
    primary_color VARCHAR(7), -- Hex color
    secondary_color VARCHAR(7),
    
    -- Tier configuration (JSON)
    tiers JSON NOT NULL, -- {"bronze": {...}, "silver": {...}, "gold": {...}}
    
    -- Status
    status ENUM('draft', 'active', 'paused', 'archived') DEFAULT 'draft',
    published_at TIMESTAMP NULL,
    
    -- Stats (cached)
    total_members INT UNSIGNED DEFAULT 0,
    monthly_revenue DECIMAL(10,2) DEFAULT 0,
    
    -- Settings
    allow_monthly BOOLEAN DEFAULT TRUE,
    allow_yearly BOOLEAN DEFAULT TRUE,
    auto_renew BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE,
    INDEX idx_artist_id (artist_id),
    INDEX idx_status (status),
    INDEX idx_slug (slug)
);
```

**Tiers JSON Structure Example:**
```json
{
  "bronze": {
    "name": "Bronze Fan",
    "price_monthly": 5000,
    "price_yearly": 50000,
    "benefits": {
      "event_discount_percentage": 5,
      "early_access_hours": 0,
      "exclusive_content": false,
      "store_discount_percentage": 0,
      "loyalty_points_multiplier": 1,
      "priority_support": false,
      "badge_icon": "bronze_star.svg"
    }
  },
  "silver": {
    "name": "Silver VIP",
    "price_monthly": 10000,
    "price_yearly": 100000,
    "benefits": {
      "event_discount_percentage": 10,
      "early_access_hours": 24,
      "exclusive_content": true,
      "store_discount_percentage": 10,
      "loyalty_points_multiplier": 1.5,
      "priority_support": true,
      "badge_icon": "silver_star.svg"
    }
  },
  "gold": {
    "name": "Gold Elite",
    "price_monthly": 20000,
    "price_yearly": 200000,
    "benefits": {
      "event_discount_percentage": 20,
      "early_access_hours": 48,
      "exclusive_content": true,
      "store_discount_percentage": 15,
      "loyalty_points_multiplier": 2,
      "priority_support": true,
      "meet_and_greet": true,
      "badge_icon": "gold_star.svg"
    }
  },
  "platinum": {
    "name": "Platinum Legend",
    "price_monthly": 50000,
    "price_yearly": 500000,
    "benefits": {
      "event_discount_percentage": 30,
      "early_access_hours": 72,
      "exclusive_content": true,
      "store_discount_percentage": 25,
      "loyalty_points_multiplier": 3,
      "priority_support": true,
      "meet_and_greet": true,
      "backstage_pass": true,
      "custom_badge": true,
      "badge_icon": "platinum_star.svg"
    }
  }
}
```

#### **loyalty_card_members** (User's Membership)
```sql
CREATE TABLE loyalty_card_members (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    loyalty_card_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    
    -- Tier information
    tier VARCHAR(50) NOT NULL, -- 'bronze', 'silver', 'gold', 'platinum'
    
    -- Subscription details
    subscription_type ENUM('monthly', 'yearly') NOT NULL,
    price_paid DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'UGX',
    
    -- Status
    status ENUM('active', 'expired', 'cancelled', 'pending') DEFAULT 'pending',
    
    -- Dates
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    renewed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    
    -- Auto-renewal
    auto_renew BOOLEAN DEFAULT TRUE,
    renewal_reminder_sent BOOLEAN DEFAULT FALSE,
    
    -- Payment info
    payment_method VARCHAR(50), -- 'mobile_money', 'credits', 'hybrid'
    payment_transaction_id VARCHAR(255),
    
    -- Stats
    total_renewals INT UNSIGNED DEFAULT 0,
    lifetime_value DECIMAL(10,2) DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (loyalty_card_id) REFERENCES loyalty_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_membership (loyalty_card_id, user_id, status),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at)
);
```

#### **loyalty_rewards** (Tier-Based Rewards)
```sql
CREATE TABLE loyalty_rewards (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    loyalty_card_id BIGINT UNSIGNED NOT NULL,
    
    -- Reward details
    name VARCHAR(255) NOT NULL,
    description TEXT,
    type ENUM('content', 'merchandise', 'experience', 'discount', 'points') NOT NULL,
    
    -- Eligibility
    required_tier VARCHAR(50) NOT NULL, -- 'silver', 'gold', 'platinum'
    
    -- Content rewards (exclusive music, videos, etc.)
    content_type VARCHAR(50), -- 'audio', 'video', 'image', 'document'
    content_url VARCHAR(500),
    
    -- Merchandise rewards
    product_id BIGINT UNSIGNED NULL,
    discount_percentage DECIMAL(5,2),
    
    -- Experience rewards (meet & greet, backstage pass)
    event_id BIGINT UNSIGNED NULL,
    experience_type VARCHAR(100),
    
    -- Points rewards
    points_amount INT UNSIGNED,
    
    -- Availability
    is_active BOOLEAN DEFAULT TRUE,
    available_from TIMESTAMP NULL,
    available_until TIMESTAMP NULL,
    max_redemptions INT UNSIGNED NULL,
    current_redemptions INT UNSIGNED DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (loyalty_card_id) REFERENCES loyalty_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE SET NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    INDEX idx_loyalty_card_id (loyalty_card_id),
    INDEX idx_type (type),
    INDEX idx_tier (required_tier)
);
```

#### **loyalty_reward_redemptions** (User Claims Reward)
```sql
CREATE TABLE loyalty_reward_redemptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    loyalty_reward_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    loyalty_card_member_id BIGINT UNSIGNED NOT NULL,
    
    -- Redemption status
    status ENUM('pending', 'fulfilled', 'cancelled') DEFAULT 'pending',
    fulfilled_at TIMESTAMP NULL,
    fulfilment_notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (loyalty_reward_id) REFERENCES loyalty_rewards(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (loyalty_card_member_id) REFERENCES loyalty_card_members(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
);
```

#### **loyalty_points** (Platform-Wide Points System)
```sql
CREATE TABLE loyalty_points (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    
    -- Points balance
    balance INT UNSIGNED DEFAULT 0,
    lifetime_earned INT UNSIGNED DEFAULT 0,
    lifetime_spent INT UNSIGNED DEFAULT 0,
    
    -- Tier multipliers (if user is member of loyalty cards)
    current_multiplier DECIMAL(3,2) DEFAULT 1.00, -- e.g., 1.5x for Silver, 2x for Gold
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### **loyalty_transactions** (Points Activity Log)
```sql
CREATE TABLE loyalty_transactions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    
    -- Transaction details
    type ENUM('earned', 'spent', 'expired', 'adjusted') NOT NULL,
    points INT NOT NULL, -- Positive for earned, negative for spent
    balance_after INT UNSIGNED NOT NULL,
    
    -- Source/reason
    source ENUM('stream', 'download', 'purchase', 'event_attendance', 'referral', 'bonus', 'admin_adjustment') NOT NULL,
    source_id BIGINT UNSIGNED NULL, -- ID of related record (song, order, event, etc.)
    source_type VARCHAR(100), -- Polymorphic relation
    description TEXT,
    
    -- Multiplier applied
    base_points INT UNSIGNED,
    multiplier DECIMAL(3,2) DEFAULT 1.00,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_source (source),
    INDEX idx_created_at (created_at)
);
```

---

### 3.2 Model Relationships

```
Artist
  - hasMany LoyaltyCard (artist can create multiple fan clubs - one per brand/alias)

LoyaltyCard
  - belongsTo Artist
  - hasMany LoyaltyCardMember (members/subscribers)
  - hasMany LoyaltyReward (rewards for this card)
  - hasMany Event (events tied to this loyalty card)

User
  - hasOne LoyaltyPoints (platform-wide points balance)
  - hasMany LoyaltyCardMemberships (can be member of multiple artist fan clubs)
  - hasMany LoyaltyRewardRedemptions

LoyaltyCardMember
  - belongsTo LoyaltyCard
  - belongsTo User
  - hasMany LoyaltyRewardRedemptions

LoyaltyReward
  - belongsTo LoyaltyCard
  - belongsTo Product (optional, for merchandise rewards)
  - belongsTo Event (optional, for event-based rewards)
  - hasMany LoyaltyRewardRedemptions

Event
  - belongsTo LoyaltyCard (optional, if event is loyalty-gated)

EventTicket
  - no direct FK, but checks user's membership via LoyaltyCardMember

LoyaltyPoints
  - belongsTo User
  - hasMany LoyaltyTransactions
```

---

## 4. Business Logic & Features

### 4.1 Artist Creates Loyalty Card

**Flow:**
1. Artist navigates to `/artist/loyalty/create`
2. Fills out form:
   - Name (e.g., "DJ Kiboko Fan Club")
   - Description ("Join my exclusive fan club for early access to events and exclusive content!")
   - Logo, banner, colors
3. Defines tiers (Bronze, Silver, Gold, Platinum):
   - Name, monthly price, yearly price
   - Benefits: event discount %, early access hours, store discount %, etc.
4. Publishes loyalty card
5. Loyalty card appears on artist's profile

**API Endpoint:**
```
POST /api/artist/loyalty-cards
Body: {
  name, description, logo, banner, colors, tiers (JSON)
}
Response: { loyalty_card }
```

---

### 4.2 Fan Joins Loyalty Card

**Flow:**
1. Fan visits artist profile → Sees "Join Fan Club" section
2. Selects tier (e.g., "Gold - 20,000 UGX/month")
3. Chooses payment method (mobile money, credits, hybrid)
4. Completes payment
5. Membership activated → Status: `active`, expires_at set to 30 days from now
6. Fan receives confirmation email with membership card (digital badge)

**API Endpoint:**
```
POST /api/loyalty-cards/{slug}/join
Body: {
  tier, subscription_type (monthly/yearly), payment_method
}
Response: { membership, payment_url (if mobile money) }
```

---

### 4.3 Tier Access Control (Events & Tickets)

**Flow:**
1. Artist creates event → Marks it as "Silver tier and above only"
2. Non-members can't see event details (if `hide_from_non_qualifying = true`)
3. Silver/Gold/Platinum members see event with discounted ticket prices
4. Members get early access to ticket sales (24-72 hours before public)
5. At checkout, discount is automatically applied

**Service Logic (TierAccessService):**
```php
public function canAccessEvent(User $user, Event $event): array
{
    if (!$event->requiresLoyaltyTier()) {
        return ['can_access' => true];
    }
    
    $membership = $user->loyaltyCardMemberships()
        ->where('loyalty_card_id', $event->loyalty_card_id)
        ->where('status', 'active')
        ->first();
    
    if (!$membership) {
        return ['can_access' => false, 'reason' => 'No membership'];
    }
    
    $tierOrder = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4];
    
    if ($tierOrder[$membership->tier] < $tierOrder[$event->required_loyalty_tier]) {
        return ['can_access' => false, 'reason' => 'Tier too low'];
    }
    
    return ['can_access' => true, 'membership' => $membership];
}

public function canPurchaseTicket(User $user, EventTicket $ticket): array
{
    $eventAccess = $this->canAccessEvent($user, $ticket->event);
    
    if (!$eventAccess['can_access']) {
        return $eventAccess;
    }
    
    $membership = $eventAccess['membership'];
    $discountPercentage = $ticket->tier_discounts[$membership->tier] ?? 0;
    $originalPrice = $ticket->price_ugx;
    $discountedPrice = $originalPrice * (1 - $discountPercentage / 100);
    
    return [
        'can_access' => true,
        'has_early_access' => $this->hasEarlyAccess($user, $ticket),
        'discount' => [
            'percentage' => $discountPercentage,
            'original_price' => $originalPrice,
            'discounted_price' => $discountedPrice,
            'savings' => $originalPrice - $discountedPrice
        ]
    ];
}

public function hasEarlyAccess(User $user, EventTicket $ticket): array
{
    $membership = $user->loyaltyCardMemberships()
        ->where('loyalty_card_id', $ticket->event->loyalty_card_id)
        ->where('status', 'active')
        ->first();
    
    if (!$membership) {
        return ['has_early_access' => false];
    }
    
    $earlyAccessHours = $ticket->tier_early_access_hours;
    $publicSaleStart = $ticket->sale_starts_at;
    $memberSaleStart = $publicSaleStart->subHours($earlyAccessHours);
    
    return [
        'has_early_access' => true,
        'early_access_starts_at' => $memberSaleStart,
        'public_sale_starts_at' => $publicSaleStart,
        'hours_advantage' => $earlyAccessHours
    ];
}
```

---

### 4.4 Loyalty Points Earning

**Points Earning Rules:**
- Stream a song: 1 point × tier multiplier
- Download a song: 5 points × multiplier
- Purchase from store: 1 point per 100 UGX × multiplier
- Attend event: 10 points × multiplier
- Refer a friend: 50 points (one-time)
- Daily login streak: 1-5 points/day

**Example:**
- Gold member (2x multiplier) streams a song → Earns 2 points
- Bronze member (1x multiplier) purchases 10,000 UGX item → Earns 100 points
- Platinum member (3x multiplier) attends event → Earns 30 points

**Service Logic (LoyaltyPointsService):**
```php
public function awardPoints(User $user, int $basePoints, string $source, $sourceId = null): void
{
    $loyaltyPoints = $user->loyaltyPoints ?? LoyaltyPoints::create(['user_id' => $user->id]);
    
    // Get highest multiplier from user's active memberships
    $multiplier = $user->loyaltyCardMemberships()
        ->where('status', 'active')
        ->get()
        ->map(function ($membership) {
            $tiers = $membership->loyaltyCard->tiers;
            return $tiers[$membership->tier]['benefits']['loyalty_points_multiplier'] ?? 1;
        })
        ->max() ?? 1;
    
    $pointsAwarded = round($basePoints * $multiplier);
    
    $loyaltyPoints->increment('balance', $pointsAwarded);
    $loyaltyPoints->increment('lifetime_earned', $pointsAwarded);
    
    LoyaltyTransaction::create([
        'user_id' => $user->id,
        'type' => 'earned',
        'points' => $pointsAwarded,
        'balance_after' => $loyaltyPoints->balance,
        'source' => $source,
        'source_id' => $sourceId,
        'base_points' => $basePoints,
        'multiplier' => $multiplier
    ]);
}
```

---

### 4.5 Membership Renewal

**Auto-Renewal Flow:**
1. Cron job runs daily: `php artisan loyalty:process-renewals`
2. Finds memberships expiring in next 3 days with `auto_renew = true`
3. Sends reminder email: "Your membership renews in 3 days"
4. On expiry date, attempts payment:
   - Mobile money: Initiates STK push
   - Credits: Deducts from wallet
   - Hybrid: Both
5. If payment succeeds:
   - Update `expires_at` (add 30 days for monthly, 365 for yearly)
   - Increment `total_renewals`
   - Add to `lifetime_value`
   - Status remains `active`
6. If payment fails:
   - Status → `expired`
   - Send email: "Your membership has expired. Renew now!"
   - User has 7-day grace period to renew

**Command:**
```php
// app/Console/Commands/ProcessLoyaltyRenewals.php
public function handle()
{
    $expiring = LoyaltyCardMember::where('status', 'active')
        ->where('auto_renew', true)
        ->where('expires_at', '<=', now())
        ->get();
    
    foreach ($expiring as $membership) {
        $this->renewMembership($membership);
    }
}
```

---

### 4.6 Rewards Redemption

**Flow:**
1. Member views available rewards on loyalty card page
2. Filters by tier (only sees rewards for their tier and below)
3. Clicks "Redeem" on reward (e.g., "Exclusive unreleased track")
4. Reward is added to their account
5. If content reward → Download link sent via email
6. If product discount → Coupon code generated
7. If experience → Confirmation email with details

**API Endpoint:**
```
POST /api/loyalty-rewards/{id}/redeem
Response: {
  redemption: { status, content_url, coupon_code },
  message: "Reward redeemed! Check your email for details."
}
```

---

## 5. Integration with Existing TesoTunes Modules

### 5.1 Credits System
- **Membership Payments**: Users can pay subscriptions with credits
- **Hybrid Payments**: 10,000 credits + 5,000 UGX for Gold tier
- **Credit Earnings**: Loyalty points can be converted to credits (100 points = 10 credits)

### 5.2 Events (Edula)
- **Tier-Gated Events**: Events visible only to specific tiers
- **Early Access**: Members get first dibs on ticket sales
- **Discounted Tickets**: Automatic tier-based discounts at checkout
- **VIP Experiences**: Platinum members get backstage passes

### 5.3 Store
- **Member Discounts**: Automatic % off on all store purchases
- **Exclusive Merch**: Products only available to Gold+ members
- **Reward Vouchers**: Redeem loyalty rewards for store credit

### 5.4 SACCO
- **Subscription Loans**: Artists can borrow to fund loyalty card setup
- **Revenue Analytics**: Track loyalty revenue vs other income streams
- **Predictable Income**: Recurring subscriptions improve loan eligibility

### 5.5 Promotions
- **Member-Only Promotions**: "Gold members: Free TikTok shoutout this week!"
- **Referral Program**: Members get bonus for referring new members

### 5.6 Analytics
- **Membership Metrics**: Track sign-ups, renewals, churn rate, LTV
- **Tier Distribution**: How many Bronze vs Gold vs Platinum members
- **Revenue Forecasting**: Predict future revenue based on active subscriptions

---

## 6. Next.js Frontend Requirements

### 6.1 Technology Stack
- **Framework**: Next.js 14 (App Router)
- **Language**: TypeScript
- **UI Library**: Shadcn UI + Tailwind CSS
- **Forms**: React Hook Form + Zod
- **State**: Zustand (global) + React Query (server state)
- **API**: Axios with Laravel Sanctum auth
- **Real-time**: Pusher for membership status updates

---

### 6.2 Pages & Routes

#### **Public Routes**
```
/loyalty - Browse all artist loyalty cards
/loyalty/[slug] - Loyalty card detail page (public preview)
/artists/[username] - Artist profile with loyalty card section
```

#### **Fan Routes (Protected)**
```
/dashboard/memberships - My active memberships
/dashboard/memberships/[id] - Membership detail with benefits, rewards
/dashboard/loyalty-points - Loyalty points balance & transaction history
/loyalty/[slug]/join - Join loyalty card (checkout flow)
```

#### **Artist Routes (Protected)**
```
/artist/loyalty - My loyalty cards dashboard
/artist/loyalty/create - Create new loyalty card
/artist/loyalty/[id]/edit - Edit loyalty card
/artist/loyalty/[id]/members - View members list
/artist/loyalty/[id]/analytics - Membership analytics (revenue, churn, etc.)
/artist/loyalty/[id]/rewards - Manage rewards
/artist/loyalty/[id]/rewards/create - Create reward
```

#### **Admin Routes (Protected)**
```
/admin/loyalty - All loyalty cards (approve, flag, ban)
/admin/loyalty/[id] - Loyalty card detail with admin actions
/admin/loyalty/analytics - Platform-wide loyalty analytics
```

---

### 6.3 UI Components

#### **LoyaltyCard Component** (Browse/Profile)
```tsx
<LoyaltyCard
  name="DJ Kiboko Fan Club"
  description="Join for exclusive perks!"
  logo="/logos/djkiboko-fanclub.png"
  banner="/banners/djkiboko-fanclub.jpg"
  tiers={[
    { name: 'Bronze', price: 5000, color: '#CD7F32' },
    { name: 'Silver', price: 10000, color: '#C0C0C0' },
    { name: 'Gold', price: 20000, color: '#FFD700' },
  ]}
  totalMembers={1250}
  artistName="DJ Kiboko"
  artistAvatar="/avatars/djkiboko.jpg"
  artistVerified={true}
/>
```

#### **TierPricingCard Component** (Checkout)
```tsx
<TierPricingCard
  tier="gold"
  name="Gold Elite"
  priceMonthly={20000}
  priceYearly={200000}
  benefits={[
    '20% off event tickets',
    '48 hours early access',
    'Exclusive unreleased tracks',
    '15% off merchandise',
    '2x loyalty points multiplier'
  ]}
  badgeIcon="/icons/gold_star.svg"
  selected={true}
  onSelect={() => setSelectedTier('gold')}
/>
```

#### **MembershipCard Component** (Dashboard)
```tsx
<MembershipCard
  loyaltyCard="DJ Kiboko Fan Club"
  tier="gold"
  tierColor="#FFD700"
  status="active"
  expiresAt="2024-03-15"
  autoRenew={true}
  benefits={[...]}
  onManage={() => router.push('/dashboard/memberships/123')}
/>
```

#### **BenefitsList Component**
```tsx
<BenefitsList
  benefits={[
    { icon: 'discount', label: '20% off events', active: true },
    { icon: 'early_access', label: '48h early access', active: true },
    { icon: 'content', label: 'Exclusive content', active: true },
    { icon: 'points', label: '2x points multiplier', active: true }
  ]}
/>
```

#### **RewardCard Component**
```tsx
<RewardCard
  name="Unreleased Track: 'Fire'"
  description="Get early access to my next single!"
  type="content"
  requiredTier="silver"
  imageUrl="/rewards/fire-single.jpg"
  available={true}
  redeemed={false}
  onRedeem={() => handleRedeem()}
/>
```

#### **LoyaltyPointsWidget Component**
```tsx
<LoyaltyPointsWidget
  balance={1250}
  lifetimeEarned={5000}
  lifetimeSpent={3750}
  currentMultiplier={2.0}
  nextReward="500 points away from Gold tier bonus"
/>
```

---

### 6.4 User Flows

#### **Flow 1: Fan Joins Loyalty Card**
1. **Browse**: Fan browses loyalty cards at `/loyalty` or artist profile
2. **Preview**: Clicks on card → `/loyalty/dj-kiboko-fan-club`
3. **Select Tier**: Compares tiers, selects "Gold - 20,000 UGX/month"
4. **Checkout**: Redirects to `/loyalty/dj-kiboko-fan-club/join`
   - Summary: Tier, price, billing cycle
   - Payment method selector: Mobile money, credits, hybrid
5. **Payment**: Completes payment
6. **Confirmation**: Success screen → "Welcome to the club!"
7. **Dashboard**: Membership appears in `/dashboard/memberships`

#### **Flow 2: Artist Creates Loyalty Card**
1. **Create**: Artist navigates to `/artist/loyalty/create`
2. **Step 1 - Basics**: Name, description, logo, banner, colors
3. **Step 2 - Tiers**: Define 3-4 tiers with pricing
4. **Step 3 - Benefits**: Configure benefits for each tier (checkboxes + inputs)
5. **Step 4 - Preview**: See how card looks to fans
6. **Publish**: Submit for review (admin approval if needed)
7. **Dashboard**: Card appears in `/artist/loyalty` (status: pending/active)

#### **Flow 3: Member Uses Early Access**
1. **Event Created**: Artist creates event, marks "Silver tier early access - 24 hours"
2. **Notification**: Silver/Gold/Platinum members receive email: "Early access to [Event Name] starts in 1 hour!"
3. **Browse**: Member visits `/events` → Sees event with "Early Access" badge
4. **Purchase**: Clicks "Buy Tickets" → Sees discounted price automatically applied
5. **Checkout**: Completes purchase with 10% discount
6. **Confirmation**: Receives ticket with loyalty badge shown

#### **Flow 4: Member Redeems Reward**
1. **Browse Rewards**: Member visits `/dashboard/memberships/[id]` → "Available Rewards" tab
2. **Filter**: Filters by tier (only sees rewards for Gold and below)
3. **Select**: Clicks "Redeem" on "Unreleased Track: 'Fire'"
4. **Confirmation Modal**: "Are you sure? This reward can only be redeemed once."
5. **Redeem**: Confirms → Reward status changes to "Redeemed"
6. **Access**: Download link appears + email sent with MP3 file
7. **History**: Appears in "Redeemed Rewards" section

---

## 7. Laravel API Endpoints (Full Specification)

### 7.1 Public Endpoints (No Auth)

#### **GET `/api/loyalty-cards`**
**Purpose**: Browse all active loyalty cards
**Query Params**:
- `artist_id` (int) - Filter by artist
- `min_price` (int) - Minimum monthly price
- `max_price` (int) - Maximum monthly price
- `sort` (string) - `popular`, `newest`, `price_asc`, `price_desc`
- `page`, `per_page`

**Response**:
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "...",
      "name": "DJ Kiboko Fan Club",
      "slug": "dj-kiboko-fan-club",
      "description": "...",
      "logo_url": "...",
      "banner_url": "...",
      "primary_color": "#FF6B00",
      "tiers": { /* JSON */ },
      "total_members": 1250,
      "status": "active",
      "artist": {
        "id": 5,
        "name": "DJ Kiboko",
        "username": "djkiboko",
        "avatar_url": "...",
        "is_verified": true
      }
    }
  ],
  "meta": { "current_page": 1, "total": 50 }
}
```

#### **GET `/api/loyalty-cards/{slug}`**
**Purpose**: Get loyalty card detail
**Response**: Full card object with tiers, artist info, sample rewards

---

### 7.2 Fan Endpoints (Auth Required)

#### **POST `/api/loyalty-cards/{slug}/join`**
**Purpose**: Join a loyalty card
**Body**:
```json
{
  "tier": "gold",
  "subscription_type": "monthly",
  "payment_method": "mobile_money",
  "mobile_number": "256700000000",
  "auto_renew": true
}
```
**Response**:
```json
{
  "membership": { /* LoyaltyCardMember object */ },
  "payment": {
    "status": "pending",
    "payment_url": "https://payment-gateway.com/...",
    "reference": "PAY-123456"
  }
}
```

#### **GET `/api/my/memberships`**
**Purpose**: Get my active/expired memberships
**Response**: Array of memberships with loyalty_card info

#### **GET `/api/my/memberships/{id}`**
**Purpose**: Get membership detail
**Response**: Membership + available rewards + usage stats

#### **PATCH `/api/my/memberships/{id}`**
**Purpose**: Update membership settings (auto_renew, tier upgrade/downgrade)
**Body**: `{ auto_renew, upgrade_tier }`

#### **POST `/api/my/memberships/{id}/cancel`**
**Purpose**: Cancel membership (remains active until expiry)
**Response**: `{ message, expires_at }`

#### **POST `/api/my/memberships/{id}/renew`**
**Purpose**: Manually renew membership
**Response**: Payment object

#### **GET `/api/my/loyalty-points`**
**Purpose**: Get loyalty points balance
**Response**:
```json
{
  "balance": 1250,
  "lifetime_earned": 5000,
  "lifetime_spent": 3750,
  "current_multiplier": 2.0
}
```

#### **GET `/api/my/loyalty-points/transactions`**
**Purpose**: Get points transaction history
**Response**: Paginated transactions

#### **POST `/api/loyalty-rewards/{id}/redeem`**
**Purpose**: Redeem a reward
**Response**:
```json
{
  "redemption": {
    "id": 123,
    "status": "fulfilled",
    "content_url": "https://...",
    "coupon_code": "GOLD20OFF"
  }
}
```

---

### 7.3 Artist Endpoints (Auth Required + Artist Role)

#### **GET `/api/artist/loyalty-cards`**
**Purpose**: Get my loyalty cards
**Response**: Array of my cards with stats

#### **POST `/api/artist/loyalty-cards`**
**Purpose**: Create loyalty card
**Body**: `{ name, description, logo, banner, colors, tiers (JSON) }`
**Response**: Created card

#### **PUT `/api/artist/loyalty-cards/{id}`**
**Purpose**: Update loyalty card
**Response**: Updated card

#### **DELETE `/api/artist/loyalty-cards/{id}`**
**Purpose**: Archive loyalty card
**Response**: `{ message }`

#### **PATCH `/api/artist/loyalty-cards/{id}/publish`**
**Purpose**: Publish draft card
**Response**: `{ status: 'active' }`

#### **GET `/api/artist/loyalty-cards/{id}/members`**
**Purpose**: Get members list
**Query Params**: `tier`, `status`, `sort`
**Response**: Paginated members

#### **GET `/api/artist/loyalty-cards/{id}/analytics`**
**Purpose**: Get analytics
**Response**:
```json
{
  "total_members": 1250,
  "new_members_this_month": 150,
  "churn_rate": 0.05,
  "monthly_revenue": 15000000,
  "tier_breakdown": {
    "bronze": 600,
    "silver": 400,
    "gold": 200,
    "platinum": 50
  },
  "revenue_by_tier": { /* ... */ },
  "renewal_rate": 0.92,
  "average_ltv": 240000
}
```

#### **GET `/api/artist/loyalty-cards/{id}/rewards`**
**Purpose**: Get rewards list
**Response**: Array of rewards

#### **POST `/api/artist/loyalty-cards/{id}/rewards`**
**Purpose**: Create reward
**Body**: `{ name, description, type, required_tier, content_url, discount_percentage, ... }`
**Response**: Created reward

#### **PUT `/api/artist/loyalty-cards/{id}/rewards/{rewardId}`**
**Purpose**: Update reward
**Response**: Updated reward

#### **DELETE `/api/artist/loyalty-cards/{id}/rewards/{rewardId}`**
**Purpose**: Delete reward
**Response**: `{ message }`

---

### 7.4 Admin Endpoints (Auth + Admin Role)

#### **GET `/api/admin/loyalty-cards`**
**Purpose**: All loyalty cards with admin filters
**Query Params**: `status` (draft, active, flagged), `artist_id`

#### **PATCH `/api/admin/loyalty-cards/{id}/approve`**
**Purpose**: Approve pending card

#### **PATCH `/api/admin/loyalty-cards/{id}/flag`**
**Purpose**: Flag card for review
**Body**: `{ reason }`

#### **PATCH `/api/admin/loyalty-cards/{id}/ban`**
**Purpose**: Ban card (violates policies)
**Body**: `{ reason }`

#### **GET `/api/admin/loyalty/analytics`**
**Purpose**: Platform-wide analytics
**Response**:
```json
{
  "total_cards": 250,
  "total_members": 15000,
  "total_revenue_this_month": 75000000,
  "platform_commission": 11250000,
  "top_cards": [ /* ... */ ],
  "churn_rate": 0.06,
  "average_tier_price": 12500
}
```

---

## 8. Implementation Roadmap (12 Weeks)

### **Phase 1: Foundation (Weeks 1-2)**
- [ ] Create database migrations (loyalty_cards, loyalty_card_members, etc.)
- [ ] Create Eloquent models with relationships
- [ ] Create seeders for testing (sample loyalty cards, memberships)
- [ ] Set up config file `config/loyalty.php`
- [ ] Write unit tests for models

### **Phase 2: Services & Business Logic (Weeks 3-4)**
- [ ] Create `TierAccessService` (canAccessEvent, canPurchaseTicket, etc.)
- [ ] Create `LoyaltyPointsService` (awardPoints, spendPoints, etc.)
- [ ] Create `RewardService` (redeemReward, checkEligibility)
- [ ] Implement middleware `CheckLoyaltyTierAccess`
- [ ] Write unit tests for services

### **Phase 3: Artist API Endpoints (Weeks 5-6)**
- [ ] Create controllers (ArtistLoyaltyController, RewardManagementController)
- [ ] Implement CRUD endpoints for loyalty cards
- [ ] Implement members list & analytics endpoints
- [ ] Implement reward management endpoints
- [ ] Write API tests

### **Phase 4: Fan API Endpoints (Weeks 7-8)**
- [ ] Create controllers (LoyaltyController, MembershipController)
- [ ] Implement join/subscribe endpoint with payment integration
- [ ] Implement membership management endpoints (cancel, renew, upgrade)
- [ ] Implement rewards redemption endpoint
- [ ] Implement loyalty points endpoints
- [ ] Write API tests

### **Phase 5: Next.js Frontend - Artist UI (Weeks 9-10)**
- [ ] Set up Next.js project structure (if not done)
- [ ] Create artist pages (dashboard, create, edit, members, analytics)
- [ ] Build tier configuration form (multi-step wizard)
- [ ] Build rewards management UI (CRUD)
- [ ] Build members list with filters/search
- [ ] Build analytics dashboard with charts

### **Phase 6: Next.js Frontend - Fan UI (Weeks 11-12)**
- [ ] Create public pages (browse, detail)
- [ ] Build join/checkout flow (tier selection, payment)
- [ ] Create membership dashboard (active, expired)
- [ ] Build membership detail page (benefits, rewards)
- [ ] Build rewards redemption UI
- [ ] Build loyalty points dashboard

### **Phase 7: Admin & Polish (Weeks 13-14)**
- [ ] Create admin pages (all cards, approve, analytics)
- [ ] Implement auto-renewal cron job
- [ ] Implement expiry notifications (email/push)
- [ ] Add real-time updates (Pusher) for membership status
- [ ] End-to-end testing
- [ ] Performance optimization
- [ ] Deploy to staging → production

---

## 9. Success Metrics & KPIs

### **Platform KPIs**
- **Total Active Memberships**: Target 10,000 in first year
- **Monthly Recurring Revenue (MRR)**: Target 50M UGX (~$13,500 USD)
- **Platform Commission**: 10-15% of MRR = 5-7.5M UGX/month
- **Churn Rate**: Target <8% monthly
- **Average LTV per Member**: Target 240,000 UGX (~$65 USD)

### **Artist KPIs**
- **Average Members per Card**: Target 100-500
- **Tier Distribution**: 50% Bronze, 30% Silver, 15% Gold, 5% Platinum
- **Renewal Rate**: Target >90%
- **Revenue per Artist**: Target 5-10M UGX/month for top artists

### **Engagement KPIs**
- **Rewards Redemption Rate**: Target 60% of members redeem ≥1 reward
- **Early Access Adoption**: Target 80% of members use early access
- **Points Activity**: Target 70% of members earn points monthly

---

## 10. Risks & Mitigation

### **Risk 1: Low Artist Adoption**
**Mitigation**: Onboard top 10 artists first as beta testers, showcase success stories

### **Risk 2: High Churn Rate**
**Mitigation**: Implement renewal reminders, offer annual discounts (2 months free)

### **Risk 3: Payment Failures**
**Mitigation**: Multiple payment methods (mobile money, credits, bank), retry logic

### **Risk 4: Reward Fulfillment Issues**
**Mitigation**: Automate digital rewards (content, coupons), manual review for experiences

### **Risk 5: Platform Commission Too High**
**Mitigation**: Start with 10% (lower than competitors), increase gradually as value grows

---

## 11. Competitive Advantages

### **vs. Patreon**
- ✅ **Localized**: Supports UGX and mobile money (MTN MoMo, Airtel Money)
- ✅ **Credits Integration**: Fans can use earned credits from streaming
- ✅ **Event Integration**: Seamless tier-based event access & discounts
- ✅ **Lower Fees**: 10% vs Patreon's 12% + payment processing

### **vs. YouTube/Twitch Memberships**
- ✅ **Not Platform-Locked**: Works across events, store, streaming
- ✅ **More Flexible Tiers**: Artists define custom tiers and benefits
- ✅ **Local Payment Methods**: Mobile money > credit cards in Uganda

### **vs. Traditional Fan Clubs**
- ✅ **Automated**: No manual membership tracking
- ✅ **Digital Benefits**: Instant rewards, automatic discounts
- ✅ **Analytics**: Track member behavior, revenue, churn

---

## 12. Future Enhancements (Post-MVP)

### **Q1 2025**
- [ ] **Gift Memberships**: Buy memberships for friends
- [ ] **Family Plans**: Discounted multi-user memberships
- [ ] **Referral Program**: Members earn credits for referrals

### **Q2 2025**
- [ ] **NFT Memberships**: Blockchain-based membership cards
- [ ] **Gamification**: Badges, streaks, leaderboards
- [ ] **Social Feed**: Member-only community feed

### **Q3 2025**
- [ ] **White-Label**: Artists customize entire membership portal
- [ ] **API for External Tools**: Integrate with Discord, Telegram, etc.
- [ ] **Advanced Analytics**: Predictive churn models, LTV forecasting

---

## 13. Documentation Requirements

### **For Developers**
- [ ] API documentation (OpenAPI/Swagger spec)
- [ ] Database schema ERD diagram
- [ ] Service architecture diagram
- [ ] Code examples for common operations
- [ ] Testing guide (unit, integration, E2E)

### **For Artists**
- [ ] "How to Create Your Fan Club" guide (video + text)
- [ ] Best practices for pricing & benefits
- [ ] Case studies from successful artists
- [ ] FAQ (common questions)

### **For Fans**
- [ ] "How Loyalty Cards Work" explainer
- [ ] Benefits comparison table
- [ ] Payment methods guide
- [ ] Troubleshooting guide

---

## 14. Conclusion

The **Loyalty System** is a **high-impact, revenue-generating feature** that transforms TesoTunes from a streaming platform into a **fan engagement and monetization ecosystem**. By enabling artists to create tiered memberships with exclusive perks, TesoTunes captures recurring revenue, increases artist income, and deepens fan loyalty.

**Current Status**: **Scaffolded but Not Implemented** (~15% complete)
- ✅ Database fields exist in Events module
- ✅ Observers, listeners, middleware created but empty
- ❌ Core models, services, controllers, routes, UI don't exist

**Implementation Priority**: **HIGH**
- Estimated effort: 14 weeks (3.5 months)
- Team size: 2-3 developers (1 backend, 1 frontend, 0.5 QA)
- ROI: High (10-15% commission on recurring revenue)

**Next Steps**:
1. Validate business model with top artists (pilot program)
2. Finalize tier pricing and platform commission structure
3. Begin Phase 1: Database & models (Week 1-2)
4. Run parallel implementation: Laravel API + Next.js UI

---

**Document Version**: 1.0  
**Date**: February 10, 2024  
**Author**: TesoTunes Engineering Team  
**Status**: ✅ Comprehensive Audit Complete - Ready for Implementation  
**Estimated Completion**: Q2 2025 (14 weeks from start)

---

## Appendix A: Sample Loyalty Card JSON

```json
{
  "name": "DJ Kiboko Fan Club",
  "slug": "dj-kiboko-fan-club",
  "description": "Join my exclusive fan club for early access, discounts, and unreleased music!",
  "logo_url": "https://cdn.tesotunes.com/loyalty/djkiboko-logo.png",
  "banner_url": "https://cdn.tesotunes.com/loyalty/djkiboko-banner.jpg",
  "primary_color": "#FF6B00",
  "secondary_color": "#1A1A1A",
  "tiers": {
    "bronze": {
      "name": "Bronze Fan",
      "price_monthly": 5000,
      "price_yearly": 50000,
      "benefits": {
        "event_discount_percentage": 5,
        "early_access_hours": 0,
        "exclusive_content": false,
        "store_discount_percentage": 0,
        "loyalty_points_multiplier": 1,
        "priority_support": false,
        "badge_icon": "bronze_star.svg"
      }
    },
    "silver": {
      "name": "Silver VIP",
      "price_monthly": 10000,
      "price_yearly": 100000,
      "benefits": {
        "event_discount_percentage": 10,
        "early_access_hours": 24,
        "exclusive_content": true,
        "store_discount_percentage": 10,
        "loyalty_points_multiplier": 1.5,
        "priority_support": true,
        "meet_and_greet": false,
        "badge_icon": "silver_star.svg"
      }
    },
    "gold": {
      "name": "Gold Elite",
      "price_monthly": 20000,
      "price_yearly": 200000,
      "benefits": {
        "event_discount_percentage": 20,
        "early_access_hours": 48,
        "exclusive_content": true,
        "store_discount_percentage": 15,
        "loyalty_points_multiplier": 2,
        "priority_support": true,
        "meet_and_greet": true,
        "backstage_pass": false,
        "badge_icon": "gold_star.svg"
      }
    },
    "platinum": {
      "name": "Platinum Legend",
      "price_monthly": 50000,
      "price_yearly": 500000,
      "benefits": {
        "event_discount_percentage": 30,
        "early_access_hours": 72,
        "exclusive_content": true,
        "store_discount_percentage": 25,
        "loyalty_points_multiplier": 3,
        "priority_support": true,
        "meet_and_greet": true,
        "backstage_pass": true,
        "custom_badge": true,
        "personal_shoutout": true,
        "badge_icon": "platinum_star.svg"
      }
    }
  },
  "status": "active",
  "allow_monthly": true,
  "allow_yearly": true,
  "auto_renew": true
}
```

## Appendix B: Example API Payloads

### Create Loyalty Card Request
```json
POST /api/artist/loyalty-cards
{
  "name": "DJ Kiboko Fan Club",
  "description": "Join my exclusive fan club...",
  "logo": "base64_or_url",
  "banner": "base64_or_url",
  "primary_color": "#FF6B00",
  "secondary_color": "#1A1A1A",
  "tiers": {
    "bronze": { /* ... */ },
    "silver": { /* ... */ },
    "gold": { /* ... */ }
  },
  "allow_monthly": true,
  "allow_yearly": true
}
```

### Join Loyalty Card Request
```json
POST /api/loyalty-cards/dj-kiboko-fan-club/join
{
  "tier": "gold",
  "subscription_type": "monthly",
  "payment_method": "mobile_money",
  "mobile_number": "256700123456",
  "auto_renew": true
}
```

### Redeem Reward Request
```json
POST /api/loyalty-rewards/123/redeem
{}
```

---

**END OF DOCUMENT**