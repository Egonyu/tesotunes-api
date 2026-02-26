# TesoTunes Edula & Social Systems - Complete Audit & Next.js Rebuild Prompt

## 🎯 Executive Summary

This document provides a comprehensive audit of **three interconnected social systems** in TesoTunes:

1. **Edula (Activity Feed)** - Platform-wide aggregated activity feed
2. **Comments System** - Universal commenting across all content types
3. **Like/Follow System** - Social engagement and relationship management

**Current Status**: **PARTIALLY IMPLEMENTED & FRAGMENTED**
- Edula exists but behaves like generic social media (Twitter/Facebook clone)
- Comments system exists but is inconsistent across modules
- Like/Follow systems exist but lack unified implementation
- **Gap**: Edula should aggregate activities from ALL modules (SACCO, Events, Store, Ojokotau, Awards, Loyalty, Forum, Podcasts), not just user posts

**Priority**: **CRITICAL** - Core social infrastructure affects all features

---

## 📊 Part 1: Current Implementation Audit

### 1.1 Edula (Activity Feed) System

#### ✅ What EXISTS

**Controllers:**
- ✅ `app/Http/Controllers/Frontend/FeedController.php` - Main feed logic
- ✅ `app/Http/Controllers/Frontend/SocialFeedController.php` - Social interactions
- ✅ `app/Http/Controllers/Backend/Admin/FeedAnalyticsController.php` - Admin analytics

**Services:**
- ✅ `app/Services/FeedService.php` - Feed aggregation & filtering
- ✅ `app/Services/FeedRankingService.php` - Content ranking algorithms
- ✅ `app/Services/FeedPreferenceService.php` - User preferences (hide, save, not interested)
- ✅ `app/Services/FeedAnalyticsService.php` - Track views, clicks, engagement
- ✅ `app/Services/ContentDiversityService.php` - Ensure diverse content

**Models:**
- ✅ `app/Models/Activity.php` - Activity stream items
- ✅ `app/Models/Post.php` - User-generated posts (social media style)
- ✅ `app/Models/FeedItem.php` - Referenced but may not exist as file

**Routes:**
```php
// routes/frontend/public.php (Lines 26-41)
Route::get('/edula', [EdulaController::class, 'index'])->name('edula');
Route::get('/edula/api/feed', [EdulaController::class, 'getFeed']);
Route::post('/edula/api/refresh', [EdulaController::class, 'refresh']);
Route::post('/edula/api/items/{uuid}/not-interested');
Route::post('/edula/api/items/{uuid}/save');
Route::post('/edula/api/items/{uuid}/track');

// Backward compatibility
Route::get('/timeline', [EdulaController::class, 'index'])->name('timeline');
```

**Current Features:**
- ✅ Activity feed with pagination
- ✅ "For You" / "Following" / "Events" / "Discover" tabs
- ✅ "Not Interested" feedback mechanism
- ✅ Save for later functionality
- ✅ Feed ranking based on engagement
- ✅ Content diversity algorithms
- ✅ Analytics tracking (views, clicks, likes, shares)

**Feed Preset Methods (FeedService.php Lines 145-200):**
```php
public function forYou(): self {
    $this->modules = ['music', 'events', 'awards', 'store', 'ojokotau', 'loyalty', 'forum'];
}

public function following(): self {
    $this->modules = ['music', 'events', 'awards', 'store', 'ojokotau', 'loyalty'];
}

public function discover(): self {
    $this->modules = ['music', 'events', 'awards', 'ojokotau'];
}
```

#### ❌ What's MISSING or BROKEN

**Critical Gaps:**

1. **Module Activity Aggregation NOT Implemented**
   - Modules are defined in `FeedService` but activities from those modules are NOT being captured
   - No activity logging when:
     - User joins SACCO / takes loan / makes payment
     - User buys item from Store (Esokoni)
     - Event is created / tickets sold / event happens
     - Award nomination submitted / voting occurs / winner announced
     - Ojokotau campaign created / funded / milestone reached
     - Loyalty card created / member joins / reward redeemed
     - Forum post/reply created / poll created / poll closed
     - Promotion purchased / promotion delivered

2. **Activity Model Gaps**
   ```php
   // Current Activity fields (app/Models/Activity.php)
   'user_id',          // Actor who performed action
   'type',             // Action type (e.g., 'uploaded_song')
   'activity_type',    // Category (e.g., 'music')
   'subject_type',     // Polymorphic - Song, Event, Order, etc.
   'subject_id',       // ID of subject
   'data',             // Additional context (JSON)
   'metadata',         // Extra metadata (JSON)
   'visibility',       // public, followers, private
   'created_at'
   ```
   
   **Missing:**
   - `module` field (explicit module name: 'sacco', 'store', 'events', etc.)
   - `priority` field (high, medium, low for feed ranking)
   - `is_prestige` field (awards, milestones deserve special treatment)
   - `media_urls` field (images/videos associated with activity)
   - `engagement_score` field (cached score for faster ranking)

3. **EdulaController Doesn't Exist**
   - Routes reference `EdulaController` but file doesn't exist
   - Current implementation uses `FeedController` instead

4. **User Posts vs System Activities Confusion**
   - `Post` model exists for user-generated social posts (Twitter-style)
   - `Activity` model exists for system-generated activities
   - **Problem**: Current UI treats everything like user posts
   - **Solution Needed**: Distinguish between:
     - **User Posts**: "Just dropped my new single! 🔥" (manual)
     - **System Activities**: "John purchased 2 tickets to Festival 2024" (automatic)

5. **No Activity Observers/Listeners for Modules**
   - Modules should automatically create Activity records when actions occur
   - Currently, only manual activity logging exists

#### 🔴 UI/UX Problems

**Current Behavior (WRONG):**
- Edula looks like Twitter/Facebook feed
- Users can post status updates, photos, videos
- Feed shows only user-generated posts
- System activities from modules are MISSING

**Expected Behavior (CORRECT):**
- Edula is an **aggregated activity stream** from ALL platform actions
- Users should see:
  - "Artist X uploaded new song 'Fire'"
  - "User Y joined Gold tier of DJ Kiboko's fan club"
  - "Festival 2024 just sold 50 tickets in 1 hour!"
  - "Campaign 'Help Artist Build Studio' reached 75% funding"
  - "Artist Z won Best Newcomer at Uganda Music Awards"
  - "Event 'Jazz Night' is happening tomorrow at 7 PM"
- Optional: Users can ALSO post manual updates (but this is secondary)

---

### 1.2 Comments System

#### ✅ What EXISTS

**Models:**
- ✅ `app/Models/PostComment.php` - Comments on Posts (social media style)
- ❌ **Generic `Comment.php` model DOES NOT EXIST as file** but is referenced everywhere

**Controllers:**
- ✅ `app/Http/Controllers/Api/Social/CommentController.php` - Universal comment API
- ✅ `app/Http/Controllers/Frontend/SocialFeedController.php` - Feed comments

**Key Features (CommentController.php):**
```php
// Lines 12-30: Polymorphic commenting
public function index(Request $request, string $commentableType, int $commentableId)
{
    $modelClass = 'App\\Models\\' . ucfirst($commentableType);
    $commentable = $modelClass::findOrFail($commentableId);
    
    $comments = Comment::where('commentable_type', $modelClass)
        ->where('commentable_id', $commentableId)
        ->approved()
        ->with(['user', 'replies'])
        ->paginate();
}

// Lines 86-153: Store comment with notifications
public function store(Request $request)
{
    $comment = Comment::create([...]);
    
    // Notify parent comment author (if reply)
    // Notify content owner
    // Log activity
}
```

**Relationships (Existing models with comments):**
```php
// app/Models/Song.php (Line 284-286)
public function comments() {
    return $this->morphMany(Comment::class, 'commentable');
}

// app/Models/Album.php (Line 105-107)
public function comments() {
    return $this->morphMany(Comment::class, 'commentable');
}

// app/Models/Artist.php (Line 163-165)
public function comments() {
    return $this->morphMany(Comment::class, 'commentable');
}

// app/Models/Playlist.php (Line 166-168)
public function comments() {
    return $this->morphMany(Comment::class, 'commentable');
}
```

**Observer:**
- ✅ `app/Observers/CommentObserver.php` - Logs activity, increments counts

#### ❌ What's MISSING or BROKEN

**Critical Issues:**

1. **No `Comment.php` Model File**
   - Controllers and observers reference `App\Models\Comment`
   - File doesn't exist in `app/Models/`
   - Only `PostComment.php` exists (specific to Posts only)

2. **Inconsistent Implementation**
   - Some models use `comments()` relationship (Song, Album, Artist, Playlist)
   - Other models (Event, Store Product, Loyalty Card, Forum Post) DON'T have comment relationships
   - No standardized trait for "commentable" functionality

3. **Missing Comment Features**
   - No comment moderation workflow (approve/reject/flag)
   - No comment editing (only create/delete)
   - No comment pinning (highlight best comments)
   - No comment sorting (newest, oldest, most liked)
   - No pagination for nested replies
   - No @mentions in comments

4. **Models That NEED Comments But Don't Have Them:**
   - ❌ `Event` - Events should have comments/discussion
   - ❌ `EventTicket` - Ticket discussions
   - ❌ `Store\Product` - Product reviews/comments
   - ❌ `Promotion` - Promotion service comments
   - ❌ `LoyaltyCard` - Fan club discussions
   - ❌ `OjokotauCampaign` - Crowdfunding campaign comments
   - ❌ `Forum\Thread` - Forum thread comments (probably has its own system)
   - ❌ `Podcast` - Podcast episode comments
   - ❌ `PodcastEpisode` - Episode-specific comments

---

### 1.3 Like/Follow System

#### ✅ What EXISTS

**Like System:**

**Model:**
- ✅ `app/Models/Like.php` - Universal polymorphic likes

```php
// app/Models/Like.php (Lines 10-88)
class Like extends Model
{
    protected $fillable = ['user_id', 'likeable_type', 'likeable_id'];
    
    public function likeable(): MorphTo {
        return $this->morphTo();
    }
    
    // Static toggle method (Lines 42-88)
    public static function toggle(User $user, Model $likeable): bool
    {
        $like = static::where('user_id', $user->id)
            ->where('likeable_type', get_class($likeable))
            ->where('likeable_id', $likeable->id)
            ->first();
        
        if ($like) {
            $like->delete();
            $likeable->decrement('like_count');
            Activity::createForUser($user, 'unliked_' . class_basename($likeable), $likeable);
            return false; // Unliked
        } else {
            static::create([...]);
            $likeable->increment('like_count');
            Activity::createForUser($user, 'liked_' . class_basename($likeable), $likeable);
            
            // Notify content owner
            if ($likeable->user && $likeable->user->id !== $user->id) {
                $likeable->user->notifications()->create([...]);
            }
            
            return true; // Liked
        }
    }
}
```

**Follow System:**

**Model:**
- ❌ **`UserFollow.php` model DOES NOT EXIST as file** but is referenced in controllers

**Relationships in User Model:**
```php
// app/Models/User.php (Lines 424-444)
public function following(): HasMany {
    return $this->hasMany(UserFollow::class, 'follower_id');
}

public function followers(): HasMany {
    return $this->hasMany(UserFollow::class, 'following_id');
}

public function followedArtists(): BelongsToMany {
    // Joins users via user_follows where following_id is artist's user_id
}

// Lines 760-790
public function isFollowing(User $user): bool {
    return $this->following()->where('following_id', $user->id)->exists();
}

public function follow(User $user): void {
    if (!$this->isFollowing($user)) {
        $this->following()->create([
            'following_id' => $user->id,
            'type' => 'user',
        ]);
        
        // Create notification
        $user->notifications()->create([
            'type' => 'new_follower',
            'title' => 'New Follower',
            'message' => "{$this->name} started following you",
        ]);
    }
}

public function unfollow(User $user): void {
    $this->following()->where('following_id', $user->id)->delete();
}
```

**Controllers:**
- ✅ `app/Http/Controllers/Api/Social/ArtistFollowController.php` - Follow/unfollow artists
- ✅ `app/Http/Controllers/Api/Mobile/MobileSocialController.php` - Mobile social actions

#### ❌ What's MISSING or BROKEN

**Critical Issues:**

1. **No `UserFollow.php` Model File**
   - Controllers reference `App\Models\UserFollow`
   - User model has relationships expecting `UserFollow`
   - File doesn't exist in `app/Models/`

2. **Inconsistent Follow Types**
   - User model has `follow(User $user)` for following users
   - But how do users follow **Artists**, **Events**, **Loyalty Cards**, **Playlists**?
   - No standardized "Followable" trait or interface

3. **Missing Follow Features**
   - No follow notifications (email/push when followed user posts)
   - No follower analytics (who are my top followers?)
   - No mutual follow detection ("X follows you back")
   - No follow suggestions ("People you may know")
   - No follower segmentation (VIP followers, inactive followers)

4. **Like System Incomplete**
   - Works well for basic like/unlike
   - But missing:
     - Like animations/feedback in UI
     - Who liked this? (list of users)
     - Like notifications (batch notifications to avoid spam)
     - Like analytics (most liked content)

5. **Models That NEED Like/Follow But Don't Have Them:**
   - ❌ `Event` - Follow event for updates
   - ❌ `LoyaltyCard` - Follow favorite fan clubs
   - ❌ `Artist` - Follow artists (currently manual)
   - ❌ `Playlist` - Follow playlists
   - ❌ `OjokotauCampaign` - Follow crowdfunding campaigns
   - ❌ `Store` - Follow stores for new products
   - ❌ `Forum\Thread` - Follow threads for new replies

---

## 🏗️ Part 2: Proposed Solution - Unified Social Infrastructure

### 2.1 Core Principle: Reusable Traits & Polymorphic Relationships

**Goal:** Build a pluggable system that ANY model can use for comments, likes, follows with minimal code.

### 2.2 Database Schema

#### **Migration 1: Universal Comments Table**

```sql
CREATE TABLE comments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    
    -- Polymorphic commentable (Song, Event, Product, etc.)
    commentable_type VARCHAR(255) NOT NULL,
    commentable_id BIGINT UNSIGNED NOT NULL,
    
    -- Author
    user_id BIGINT UNSIGNED NOT NULL,
    
    -- Threading support
    parent_id BIGINT UNSIGNED NULL,
    thread_path VARCHAR(500), -- e.g., "1/5/12" for nested hierarchy
    depth INT UNSIGNED DEFAULT 0,
    
    -- Content
    content TEXT NOT NULL,
    content_html TEXT, -- Sanitized HTML with mentions, hashtags
    
    -- Moderation
    status ENUM('pending', 'approved', 'rejected', 'flagged', 'deleted') DEFAULT 'approved',
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    
    -- Engagement
    likes_count INT UNSIGNED DEFAULT 0,
    replies_count INT UNSIGNED DEFAULT 0,
    
    -- Features
    is_pinned BOOLEAN DEFAULT FALSE,
    is_edited BOOLEAN DEFAULT FALSE,
    edited_at TIMESTAMP NULL,
    
    -- Metadata
    metadata JSON, -- Mentions, hashtags, links, etc.
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE,
    FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_commentable (commentable_type, commentable_id),
    INDEX idx_user (user_id),
    INDEX idx_parent (parent_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);
```

#### **Migration 2: Unified Likes Table** (Already exists, verify schema)

```sql
CREATE TABLE likes (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    
    -- Polymorphic likeable (Song, Comment, Post, Event, etc.)
    likeable_type VARCHAR(255) NOT NULL,
    likeable_id BIGINT UNSIGNED NOT NULL,
    
    -- Liker
    user_id BIGINT UNSIGNED NOT NULL,
    
    -- Type (like, love, save, bookmark)
    type ENUM('like', 'love', 'save', 'bookmark') DEFAULT 'like',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (user_id, likeable_type, likeable_id, type),
    INDEX idx_likeable (likeable_type, likeable_id),
    INDEX idx_user (user_id)
);
```

#### **Migration 3: Follows Table** (Create UserFollow model)

```sql
CREATE TABLE user_follows (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    
    -- Follower (who follows)
    follower_id BIGINT UNSIGNED NOT NULL,
    
    -- Polymorphic followable (User, Artist, Event, LoyaltyCard, etc.)
    followable_type VARCHAR(255) NOT NULL,
    followable_id BIGINT UNSIGNED NOT NULL,
    
    -- Metadata
    type VARCHAR(50) DEFAULT 'user', -- user, artist, event, playlist, store, etc.
    notification_enabled BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_follow (follower_id, followable_type, followable_id),
    INDEX idx_followable (followable_type, followable_id),
    INDEX idx_follower (follower_id),
    INDEX idx_type (type)
);
```

#### **Migration 4: Enhanced Activities Table**

```sql
-- Add new columns to existing activities table
ALTER TABLE activities ADD COLUMN module VARCHAR(50) AFTER activity_type;
ALTER TABLE activities ADD COLUMN priority ENUM('high', 'medium', 'low') DEFAULT 'medium';
ALTER TABLE activities ADD COLUMN is_prestige BOOLEAN DEFAULT FALSE;
ALTER TABLE activities ADD COLUMN media_urls JSON;
ALTER TABLE activities ADD COLUMN engagement_score DECIMAL(10,2) DEFAULT 0;
ALTER TABLE activities ADD INDEX idx_module (module);
ALTER TABLE activities ADD INDEX idx_priority (priority);
ALTER TABLE activities ADD INDEX idx_is_prestige (is_prestige);
ALTER TABLE activities ADD INDEX idx_engagement_score (engagement_score);
```

---

### 2.3 Reusable Traits

#### **Trait: HasComments**

```php
<?php

namespace App\Traits;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    /**
     * Get all comments for this model
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')
            ->whereNull('parent_id')
            ->orderBy('created_at', 'desc');
    }
    
    /**
     * Get all comments including replies
     */
    public function allComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
    
    /**
     * Get approved comments only
     */
    public function approvedComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')
            ->where('status', 'approved')
            ->whereNull('parent_id');
    }
    
    /**
     * Get comment count (use cached value if exists)
     */
    public function getCommentsCountAttribute(): int
    {
        return $this->comments_count ?? $this->comments()->count();
    }
    
    /**
     * Check if user has commented on this
     */
    public function hasCommentedBy(User $user): bool
    {
        return $this->allComments()->where('user_id', $user->id)->exists();
    }
    
    /**
     * Boot trait - ensure comments_count column exists
     */
    public static function bootHasComments()
    {
        // Optionally add event listeners for cache invalidation
    }
}
```

**Usage:**
```php
// In any model (Song, Event, Product, etc.)
use App\Traits\HasComments;

class Song extends Model
{
    use HasComments;
    
    // That's it! Now Song has all comment functionality
}
```

#### **Trait: HasLikes**

```php
<?php

namespace App\Traits;

use App\Models\Like;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasLikes
{
    /**
     * Get all likes for this model
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable')
            ->where('type', 'like');
    }
    
    /**
     * Get saves/bookmarks for this model
     */
    public function saves(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable')
            ->where('type', 'save');
    }
    
    /**
     * Get like count (use cached value if exists)
     */
    public function getLikesCountAttribute(): int
    {
        return $this->likes_count ?? $this->likes()->count();
    }
    
    /**
     * Check if user liked this
     */
    public function isLikedBy(User $user): bool
    {
        return $this->likes()->where('user_id', $user->id)->exists();
    }
    
    /**
     * Toggle like by user
     */
    public function toggleLike(User $user): bool
    {
        return Like::toggle($user, $this);
    }
    
    /**
     * Get users who liked this
     */
    public function likers()
    {
        return $this->morphToMany(User::class, 'likeable', 'likes')
            ->where('likes.type', 'like')
            ->withTimestamps();
    }
}
```

#### **Trait: IsFollowable**

```php
<?php

namespace App\Traits;

use App\Models\UserFollow;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait IsFollowable
{
    /**
     * Get all followers for this model
     */
    public function followers(): MorphMany
    {
        return $this->morphMany(UserFollow::class, 'followable');
    }
    
    /**
     * Get follower count (use cached value if exists)
     */
    public function getFollowersCountAttribute(): int
    {
        return $this->followers_count ?? $this->followers()->count();
    }
    
    /**
     * Check if user follows this
     */
    public function isFollowedBy(User $user): bool
    {
        return $this->followers()->where('follower_id', $user->id)->exists();
    }
    
    /**
     * Get follower users
     */
    public function followerUsers()
    {
        return $this->morphToMany(User::class, 'followable', 'user_follows', 'followable_id', 'follower_id')
            ->withTimestamps();
    }
    
    /**
     * Notify followers about new activity
     */
    public function notifyFollowers(string $type, string $message, array $data = [])
    {
        $followers = $this->followers()->where('notification_enabled', true)->get();
        
        foreach ($followers as $follow) {
            $follow->follower->notifications()->create([
                'type' => $type,
                'title' => class_basename($this) . ' Update',
                'message' => $message,
                'data' => $data,
            ]);
        }
    }
}
```

#### **Trait: CreatesActivities**

```php
<?php

namespace App\Traits;

use App\Models\Activity;
use App\Services\ActivityService;

trait CreatesActivities
{
    /**
     * Log activity when model is created
     */
    public static function bootCreatesActivities()
    {
        static::created(function ($model) {
            if (method_exists($model, 'shouldCreateActivity') && !$model->shouldCreateActivity()) {
                return;
            }
            
            ActivityService::logModelCreated($model);
        });
        
        static::updated(function ($model) {
            if (method_exists($model, 'getSignificantChanges')) {
                $changes = $model->getSignificantChanges();
                if (!empty($changes)) {
                    ActivityService::logModelUpdated($model, $changes);
                }
            }
        });
    }
    
    /**
     * Get the module this model belongs to
     */
    abstract public function getActivityModule(): string;
    
    /**
     * Get the activity type for creation
     */
    public function getActivityType(): string
    {
        return 'created_' . strtolower(class_basename($this));
    }
    
    /**
     * Get activity data
     */
    public function getActivityData(): array
    {
        return [
            'title' => $this->title ?? $this->name ?? null,
            'description' => $this->description ?? null,
        ];
    }
    
    /**
     * Should this model create activity?
     */
    public function shouldCreateActivity(): bool
    {
        return true; // Override in model if needed
    }
}
```

---

### 2.4 Models to Create/Update

#### **Create: `app/Models/Comment.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Comment extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'uuid',
        'commentable_type',
        'commentable_id',
        'user_id',
        'parent_id',
        'thread_path',
        'depth',
        'content',
        'content_html',
        'status',
        'moderated_by',
        'moderated_at',
        'rejection_reason',
        'likes_count',
        'replies_count',
        'is_pinned',
        'is_edited',
        'edited_at',
        'metadata',
    ];
    
    protected $casts = [
        'metadata' => 'array',
        'is_pinned' => 'boolean',
        'is_edited' => 'boolean',
        'moderated_at' => 'datetime',
        'edited_at' => 'datetime',
    ];
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($comment) {
            $comment->uuid = $comment->uuid ?? (string) Str::uuid();
        });
    }
    
    // Relationships
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }
    
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }
    
    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }
    
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
    
    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }
    
    // Methods
    public function approve(User $moderator = null)
    {
        $this->update([
            'status' => 'approved',
            'moderated_by' => $moderator?->id,
            'moderated_at' => now(),
        ]);
    }
    
    public function reject(User $moderator, string $reason)
    {
        $this->update([
            'status' => 'rejected',
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
```

#### **Create: `app/Models/UserFollow.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserFollow extends Model
{
    const UPDATED_AT = null; // No updated_at column
    
    protected $fillable = [
        'follower_id',
        'followable_type',
        'followable_id',
        'type',
        'notification_enabled',
    ];
    
    protected $casts = [
        'notification_enabled' => 'boolean',
    ];
    
    // Relationships
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }
    
    public function followable(): MorphTo
    {
        return $this->morphTo();
    }
    
    // Static Methods
    public static function toggle(User $follower, Model $followable, string $type = 'user'): bool
    {
        $follow = static::where('follower_id', $follower->id)
            ->where('followable_type', get_class($followable))
            ->where('followable_id', $followable->id)
            ->first();
        
        if ($follow) {
            $follow->delete();
            
            // Decrement followers_count if column exists
            if (method_exists($followable, 'decrement')) {
                $followable->decrement('followers_count');
            }
            
            return false; // Unfollowed
        } else {
            static::create([
                'follower_id' => $follower->id,
                'followable_type' => get_class($followable),
                'followable_id' => $followable->id,
                'type' => $type,
            ]);
            
            // Increment followers_count if column exists
            if (method_exists($followable, 'increment')) {
                $followable->increment('followers_count');
            }
            
            // Create notification
            if (method_exists($followable, 'user') && $followable->user) {
                $followable->user->notifications()->create([
                    'type' => 'new_follower',
                    'title' => 'New Follower',
                    'message' => "{$follower->name} started following your " . class_basename($followable),
                    'data' => [
                        'follower_id' => $follower->id,
                        'followable_type' => get_class($followable),
                        'followable_id' => $followable->id,
                    ],
                ]);
            }
            
            return true; // Followed
        }
    }
}
```

#### **Update: `app/Models/Activity.php`** (Add new fields)

```php
// Add to $fillable array
'module',           // 'music', 'events', 'store', 'sacco', 'ojokotau', 'loyalty', 'forum', 'awards'
'priority',         // 'high', 'medium', 'low'
'is_prestige',      // Boolean - awards, milestones, achievements
'media_urls',       // Array of image/video URLs
'engagement_score', // Calculated engagement for ranking

// Add to $casts
'is_prestige' => 'boolean',
'media_urls' => 'array',
'engagement_score' => 'float',

// Add scopes
public function scopeForModule($query, string $module)
{
    return $query->where('module', $module);
}

public function scopePrestige($query)
{
    return $query->where('is_prestige', true);
}

public function scopeHighPriority($query)
{
    return $query->where('priority', 'high');
}
```

---

### 2.5 Enhanced ActivityService

#### **Update: `app/Services/ActivityService.php`**

```php
<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityService
{
    /**
     * Log activity for a model creation
     */
    public static function logModelCreated(Model $model, User $actor = null): ?Activity
    {
        if (!$actor) {
            $actor = $model->user ?? $model->creator ?? auth()->user();
        }
        
        if (!$actor) {
            return null; // No actor, skip activity
        }
        
        return Activity::create([
            'user_id' => $actor->id,
            'type' => 'created_' . strtolower(class_basename($model)),
            'activity_type' => strtolower(class_basename($model)),
            'subject_type' => get_class($model),
            'subject_id' => $model->id,
            'module' => self::getModuleForModel($model),
            'priority' => self::getPriorityForModel($model),
            'is_prestige' => self::isPrestige($model),
            'data' => self::getActivityData($model),
            'media_urls' => self::getMediaUrls($model),
            'visibility' => $model->visibility ?? 'public',
            'created_at' => now(),
        ]);
    }
    
    /**
     * Get module for a model
     */
    protected static function getModuleForModel(Model $model): string
    {
        $class = class_basename($model);
        
        $moduleMap = [
            'Song' => 'music',
            'Album' => 'music',
            'Playlist' => 'music',
            'Event' => 'events',
            'EventTicket' => 'events',
            'Order' => 'store',
            'Product' => 'store',
            'Promotion' => 'promotions',
            'LoyaltyCard' => 'loyalty',
            'LoyaltyCardMember' => 'loyalty',
            'LoyaltyReward' => 'loyalty',
            'SaccoLoan' => 'sacco',
            'SaccoMember' => 'sacco',
            'OjokotauCampaign' => 'ojokotau',
            'OjokotauPledge' => 'ojokotau',
            'Award' => 'awards',
            'AwardNomination' => 'awards',
            'ForumThread' => 'forum',
            'ForumPost' => 'forum',
            'Poll' => 'polls',
            'Podcast' => 'podcasts',
            'PodcastEpisode' => 'podcasts',
        ];
        
        return $moduleMap[$class] ?? 'general';
    }
    
    /**
     * Get priority for model (high, medium, low)
     */
    protected static function getPriorityForModel(Model $model): string
    {
        // High priority: Awards, milestones, major achievements
        $highPriority = ['Award', 'AwardNomination', 'Event'];
        
        // Medium priority: Content creation, purchases, loyalty
        $mediumPriority = ['Song', 'Album', 'Order', 'LoyaltyCard', 'OjokotauCampaign'];
        
        $class = class_basename($model);
        
        if (in_array($class, $highPriority)) {
            return 'high';
        }
        
        if (in_array($class, $mediumPriority)) {
            return 'medium';
        }
        
        return 'low';
    }
    
    /**
     * Check if model represents prestige activity
     */
    protected static function isPrestige(Model $model): bool
    {
        $prestigeClasses = ['Award', 'AwardNomination', 'Milestone', 'Achievement'];
        return in_array(class_basename($model), $prestigeClasses);
    }
    
    /**
     * Get activity data from model
     */
    protected static function getActivityData(Model $model): array
    {
        return [
            'title' => $model->title ?? $model->name ?? null,
            'description' => $model->description ?? null,
            'price' => $model->price ?? $model->price_ugx ?? null,
            'image' => $model->image ?? $model->artwork ?? $model->banner ?? null,
        ];
    }
    
    /**
     * Get media URLs from model
     */
    protected static function getMediaUrls(Model $model): array
    {
        $urls = [];
        
        if (isset($model->artwork)) {
            $urls[] = $model->artwork;
        }
        
        if (isset($model->banner)) {
            $urls[] = $model->banner;
        }
        
        if (isset($model->image)) {
            $urls[] = $model->image;
        }
        
        if (method_exists($model, 'media') && $model->media) {
            foreach ($model->media as $media) {
                $urls[] = $media->url ?? $media->path;
            }
        }
        
        return array_filter($urls);
    }
    
    /**
     * Module-specific activity loggers
     */
    
    public static function logSongUploaded(Song $song, User $artist): Activity
    {
        return self::logModelCreated($song, $artist);
    }
    
    public static function logEventCreated(Event $event, User $organizer): Activity
    {
        return self::logModelCreated($event, $organizer);
    }
    
    public static function logOrderPlaced(Order $order, User $buyer): Activity
    {
        return Activity::create([
            'user_id' => $buyer->id,
            'type' => 'placed_order',
            'activity_type' => 'order',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'module' => 'store',
            'priority' => 'medium',
            'data' => [
                'order_number' => $order->order_number,
                'total_amount' => $order->total_amount,
                'items_count' => $order->items->count(),
            ],
            'created_at' => now(),
        ]);
    }
    
    public static function logLoyaltyMemberJoined(LoyaltyCardMember $member): Activity
    {
        return Activity::create([
            'user_id' => $member->user_id,
            'type' => 'joined_loyalty_card',
            'activity_type' => 'loyalty',
            'subject_type' => LoyaltyCardMember::class,
            'subject_id' => $member->id,
            'module' => 'loyalty',
            'priority' => 'medium',
            'data' => [
                'loyalty_card_name' => $member->loyaltyCard->name,
                'tier' => $member->tier,
            ],
            'created_at' => now(),
        ]);
    }
    
    public static function logCampaignFunded(OjokotauCampaign $campaign, User $backer, $amount): Activity
    {
        return Activity::create([
            'user_id' => $backer->id,
            'type' => 'funded_campaign',
            'activity_type' => 'ojokotau',
            'subject_type' => OjokotauCampaign::class,
            'subject_id' => $campaign->id,
            'module' => 'ojokotau',
            'priority' => 'medium',
            'data' => [
                'campaign_title' => $campaign->title,
                'amount' => $amount,
                'percentage_funded' => $campaign->percentage_funded,
            ],
            'created_at' => now(),
        ]);
    }
    
    public static function logAwardWon(Award $award, Artist $artist): Activity
    {
        return Activity::create([
            'user_id' => $artist->user_id,
            'type' => 'won_award',
            'activity_type' => 'award',
            'subject_type' => Award::class,
            'subject_id' => $award->id,
            'module' => 'awards',
            'priority' => 'high',
            'is_prestige' => true,
            'data' => [
                'award_name' => $award->name,
                'category' => $award->category,
            ],
            'created_at' => now(),
        ]);
    }
}
```

---

### 2.6 Universal API Endpoints

#### **Routes: `routes/api/social.php`** (Create new file)

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Social\CommentController;
use App\Http\Controllers\Api\Social\LikeController;
use App\Http\Controllers\Api\Social\FollowController;

Route::middleware(['auth:sanctum'])->group(function () {
    
    // ════════════════════════════════════════════════════════════
    // COMMENTS - Universal commenting on any model
    // ════════════════════════════════════════════════════════════
    
    // Get comments for any model
    // GET /api/social/comments/{type}/{id}
    // Example: /api/social/comments/song/123
    Route::get('comments/{commentableType}/{commentableId}', [CommentController::class, 'index']);
    
    // Post comment
    // POST /api/social/comments
    // Body: { commentable_type, commentable_id, content, parent_id }
    Route::post('comments', [CommentController::class, 'store']);
    
    // Update comment
    Route::put('comments/{id}', [CommentController::class, 'update']);
    
    // Delete comment
    Route::delete('comments/{id}', [CommentController::class, 'destroy']);
    
    // Pin comment (model owner or admin only)
    Route::post('comments/{id}/pin', [CommentController::class, 'pin']);
    
    // Like comment
    Route::post('comments/{id}/like', [CommentController::class, 'like']);
    
    // Flag comment for moderation
    Route::post('comments/{id}/flag', [CommentController::class, 'flag']);
    
    
    // ════════════════════════════════════════════════════════════
    // LIKES - Universal like/unlike on any model
    // ════════════════════════════════════════════════════════════
    
    // Toggle like on any model
    // POST /api/social/like/{type}/{id}
    // Example: /api/social/like/song/123
    Route::post('like/{likeableType}/{likeableId}', [LikeController::class, 'toggle']);
    
    // Get likes for a model
    Route::get('likes/{likeableType}/{likeableId}', [LikeController::class, 'index']);
    
    // Check if user liked
    Route::get('likes/{likeableType}/{likeableId}/check', [LikeController::class, 'check']);
    
    // Save/bookmark
    Route::post('save/{likeableType}/{likeableId}', [LikeController::class, 'save']);
    
    
    // ════════════════════════════════════════════════════════════
    // FOLLOWS - Universal follow/unfollow
    // ════════════════════════════════════════════════════════════
    
    // Toggle follow on any model
    // POST /api/social/follow/{type}/{id}
    // Example: /api/social/follow/artist/123
    Route::post('follow/{followableType}/{followableId}', [FollowController::class, 'toggle']);
    
    // Get followers for a model
    Route::get('followers/{followableType}/{followableId}', [FollowController::class, 'followers']);
    
    // Get my following (what I'm following)
    Route::get('following', [FollowController::class, 'following']);
    
    // Check if I'm following
    Route::get('following/{followableType}/{followableId}/check', [FollowController::class, 'check']);
    
    // Update notification settings for a follow
    Route::patch('following/{followableType}/{followableId}/notifications', [FollowController::class, 'updateNotifications']);
});
```

#### **Controller: `app/Http/Controllers/Api/Social/LikeController.php`** (Create)

```php
<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Like;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LikeController extends Controller
{
    /**
     * Toggle like on any model
     * POST /api/social/like/{type}/{id}
     */
    public function toggle(Request $request, string $likeableType, int $likeableId): JsonResponse
    {
        $user = $request->user();
        
        $modelClass = 'App\\Models\\' . ucfirst($likeableType);
        
        if (!class_exists($modelClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid likeable type'
            ], 400);
        }
        
        $likeable = $modelClass::findOrFail($likeableId);
        
        $isLiked = Like::toggle($user, $likeable);
        
        return response()->json([
            'success' => true,
            'liked' => $isLiked,
            'likes_count' => $likeable->fresh()->likes_count ?? $likeable->likes()->count(),
            'message' => $isLiked ? 'Liked' : 'Unliked',
        ]);
    }
    
    /**
     * Get likes for a model
     */
    public function index(Request $request, string $likeableType, int $likeableId): JsonResponse
    {
        $modelClass = 'App\\Models\\' . ucfirst($likeableType);
        $likeable = $modelClass::findOrFail($likeableId);
        
        $likes = $likeable->likes()
            ->with('user:id,name,avatar_url')
            ->latest()
            ->paginate(50);
        
        return response()->json([
            'success' => true,
            'likes' => $likes,
        ]);
    }
    
    /**
     * Check if user liked
     */
    public function check(Request $request, string $likeableType, int $likeableId): JsonResponse
    {
        $user = $request->user();
        $modelClass = 'App\\Models\\' . ucfirst($likeableType);
        $likeable = $modelClass::findOrFail($likeableId);
        
        $isLiked = $likeable->isLikedBy($user);
        
        return response()->json([
            'success' => true,
            'liked' => $isLiked,
        ]);
    }
    
    /**
     * Save/bookmark
     */
    public function save(Request $request, string $likeableType, int $likeableId): JsonResponse
    {
        $user = $request->user();
        $modelClass = 'App\\Models\\' . ucfirst($likeableType);
        $likeable = $modelClass::findOrFail($likeableId);
        
        $save = Like::where('user_id', $user->id)
            ->where('likeable_type', $modelClass)
            ->where('likeable_id', $likeable->id)
            ->where('type', 'save')
            ->first();
        
        if ($save) {
            $save->delete();
            $isSaved = false;
        } else {
            Like::create([
                'user_id' => $user->id,
                'likeable_type' => $modelClass,
                'likeable_id' => $likeable->id,
                'type' => 'save',
            ]);
            $isSaved = true;
        }
        
        return response()->json([
            'success' => true,
            'saved' => $isSaved,
            'message' => $isSaved ? 'Saved' : 'Unsaved',
        ]);
    }
}
```

#### **Controller: `app/Http/Controllers/Api/Social/FollowController.php`** (Create)

```php
<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\UserFollow;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FollowController extends Controller
{
    /**
     * Toggle follow on any model
     * POST /api/social/follow/{type}/{id}
     */
    public function toggle(Request $request, string $followableType, int $followableId): JsonResponse
    {
        $user = $request->user();
        
        $modelClass = 'App\\Models\\' . ucfirst($followableType);
        
        if (!class_exists($modelClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid followable type'
            ], 400);
        }
        
        $followable = $modelClass::findOrFail($followableId);
        
        $type = $this->getFollowType($followableType);
        $isFollowing = UserFollow::toggle($user, $followable, $type);
        
        return response()->json([
            'success' => true,
            'following' => $isFollowing,
            'followers_count' => $followable->fresh()->followers_count ?? $followable->followers()->count(),
            'message' => $isFollowing ? 'Following' : 'Unfollowed',
        ]);
    }
    
    /**
     * Get followers for a model
     */
    public function followers(Request $request, string $followableType, int $followableId): JsonResponse
    {
        $modelClass = 'App\\Models\\' . ucfirst($followableType);
        $followable = $modelClass::findOrFail($followableId);
        
        $followers = $followable->followers()
            ->with('follower:id,name,avatar_url')
            ->latest()
            ->paginate(50);
        
        return response()->json([
            'success' => true,
            'followers' => $followers,
        ]);
    }
    
    /**
     * Get my following
     */
    public function following(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->get('type'); // Optional filter by type
        
        $query = UserFollow::where('follower_id', $user->id)
            ->with('followable');
        
        if ($type) {
            $query->where('type', $type);
        }
        
        $following = $query->latest()->paginate(50);
        
        return response()->json([
            'success' => true,
            'following' => $following,
        ]);
    }
    
    /**
     * Check if I'm following
     */
    public function check(Request $request, string $followableType, int $followableId): JsonResponse
    {
        $user = $request->user();
        $modelClass = 'App\\Models\\' . ucfirst($followableType);
        $followable = $modelClass::findOrFail($followableId);
        
        $isFollowing = $followable->isFollowedBy($user);
        
        return response()->json([
            'success' => true,
            'following' => $isFollowing,
        ]);
    }
    
    /**
     * Update notification settings
     */
    public function updateNotifications(Request $request, string $followableType, int $followableId): JsonResponse
    {
        $user = $request->user();
        $modelClass = 'App\\Models\\' . ucfirst($followableType);
        
        $validated = $request->validate([
            'notification_enabled' => 'required|boolean',
        ]);
        
        $follow = UserFollow::where('follower_id', $user->id)
            ->where('followable_type', $modelClass)
            ->where('followable_id', $followableId)
            ->firstOrFail();
        
        $follow->update($validated);
        
        return response()->json([
            'success' => true,
            'message' => 'Notification settings updated',
        ]);
    }
    
    /**
     * Get follow type from model name
     */
    protected function getFollowType(string $followableType): string
    {
        $typeMap = [
            'user' => 'user',
            'artist' => 'artist',
            'event' => 'event',
            'playlist' => 'playlist',
            'store' => 'store',
            'loyaltycard' => 'loyalty_card',
            'ojokotaucampaign' => 'campaign',
        ];
        
        return $typeMap[strtolower($followableType)] ?? 'general';
    }
}
```

---

### 2.7 Edula Feed Reconstruction

#### **Create: `app/Http/Controllers/Frontend/EdulaController.php`**

```php
<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\FeedService;
use App\Services\FeedRankingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EdulaController extends Controller
{
    public function __construct(
        protected FeedService $feedService,
        protected FeedRankingService $rankingService
    ) {}
    
    /**
     * Main Edula feed page
     */
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'for_you');
        
        // If API request, return JSON
        if ($request->expectsJson() || $request->is('*/api/*')) {
            return $this->getFeed($request);
        }
        
        // Return Blade view (will be replaced with Next.js)
        return view('frontend.edula.index', compact('tab'));
    }
    
    /**
     * Get feed API
     * GET /edula/api/feed?tab=for_you&page=1
     */
    public function getFeed(Request $request): JsonResponse
    {
        $tab = $request->get('tab', 'for_you');
        $page = $request->get('page', 1);
        $user = auth()->user();
        
        // Build feed based on tab
        $feedBuilder = $this->feedService->forUser($user);
        
        switch ($tab) {
            case 'following':
                // Only activities from followed users/artists
                $feedBuilder->following();
                break;
                
            case 'events':
                // Only events module activities
                $feedBuilder->events();
                break;
                
            case 'music':
                // Only music module activities
                $feedBuilder->music();
                break;
                
            case 'awards':
                // Only awards/prestige activities
                $feedBuilder->awards();
                break;
                
            case 'discover':
                // Trending content
                $feedBuilder->discover();
                break;
                
            default: // 'for_you'
                // Personalized mix from all modules
                $feedBuilder->forYou();
        }
        
        // Get feed items
        $feed = $feedBuilder->paginate($page);
        
        // Transform to API response
        return response()->json([
            'success' => true,
            'tab' => $tab,
            'feed' => [
                'items' => $feed->items(),
                'pagination' => [
                    'current_page' => $feed->currentPage(),
                    'total' => $feed->total(),
                    'per_page' => $feed->perPage(),
                    'has_more' => $feed->hasMorePages(),
                ],
            ],
        ]);
    }
    
    /**
     * Refresh feed
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = auth()->user();
        $this->feedService->forUser($user)->clearCache();
        
        return response()->json([
            'success' => true,
            'message' => 'Feed refreshed',
        ]);
    }
    
    /**
     * Mark item as not interested
     */
    public function notInterested(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|in:not_relevant,seen_too_often,dont_like_genre,inappropriate,other',
        ]);
        
        // TODO: Implement preference learning
        
        return response()->json([
            'success' => true,
            'message' => 'Feedback recorded',
        ]);
    }
    
    /**
     * Save item for later
     */
    public function saveItem(Request $request, string $uuid): JsonResponse
    {
        // TODO: Implement save functionality
        
        return response()->json([
            'success' => true,
            'message' => 'Item saved',
        ]);
    }
    
    /**
     * Track interaction (click, view, share)
     */
    public function trackInteraction(Request $request, string $uuid): JsonResponse
    {
        $type = $request->get('type', 'view'); // view, click, share
        
        // TODO: Track analytics
        
        return response()->json([
            'success' => true,
        ]);
    }
}
```

---

## 🎨 Part 3: Next.js Frontend Requirements

### 3.1 Edula Feed UI Components

**Page: `/app/(main)/edula/page.tsx`**

```typescript
'use client';

import { useState, useEffect } from 'react';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { ActivityCard } from '@/components/edula/ActivityCard';
import { InfiniteScroll } from '@/components/ui/InfiniteScroll';
import { feedApi } from '@/lib/api/feed';

export default function EdulaPage() {
  const [tab, setTab] = useState('for_you');
  const [activities, setActivities] = useState([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  
  const loadFeed = async () => {
    const response = await feedApi.getFeed(tab, page);
    setActivities(prev => [...prev, ...response.data.feed.items]);
    setHasMore(response.data.feed.pagination.has_more);
  };
  
  useEffect(() => {
    loadFeed();
  }, [tab, page]);
  
  return (
    <div className="container max-w-4xl py-6">
      <h1 className="text-3xl font-bold mb-6">Edula</h1>
      
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="for_you">For You</TabsTrigger>
          <TabsTrigger value="following">Following</TabsTrigger>
          <TabsTrigger value="music">Music</TabsTrigger>
          <TabsTrigger value="events">Events</TabsTrigger>
          <TabsTrigger value="awards">Awards</TabsTrigger>
          <TabsTrigger value="discover">Discover</TabsTrigger>
        </TabsList>
        
        <TabsContent value={tab} className="mt-6">
          <InfiniteScroll
            hasMore={hasMore}
            loadMore={() => setPage(p => p + 1)}
          >
            <div className="space-y-4">
              {activities.map((activity) => (
                <ActivityCard key={activity.id} activity={activity} />
              ))}
            </div>
          </InfiniteScroll>
        </TabsContent>
      </Tabs>
    </div>
  );
}
```

**Component: `components/edula/ActivityCard.tsx`**

```typescript
'use client';

import { Card } from '@/components/ui/card';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Heart, MessageCircle, Share2, Bookmark } from 'lucide-react';
import { CommentSection } from './CommentSection';
import { useState } from 'react';
import { socialApi } from '@/lib/api/social';

interface ActivityCardProps {
  activity: Activity;
}

export function ActivityCard({ activity }: ActivityCardProps) {
  const [liked, setLiked] = useState(activity.is_liked);
  const [likesCount, setLikesCount] = useState(activity.likes_count);
  const [showComments, setShowComments] = useState(false);
  
  const handleLike = async () => {
    const result = await socialApi.toggleLike(
      activity.subject_type.toLowerCase().split('\\').pop(),
      activity.subject_id
    );
    setLiked(result.liked);
    setLikesCount(result.likes_count);
  };
  
  return (
    <Card className="p-6">
      {/* Actor Info */}
      <div className="flex items-start gap-3 mb-4">
        <Avatar src={activity.user.avatar_url} alt={activity.user.name} />
        <div>
          <p className="font-semibold">{activity.user.name}</p>
          <p className="text-sm text-muted-foreground">
            {activity.type_display} · {activity.created_at_human}
          </p>
        </div>
      </div>
      
      {/* Activity Content */}
      <div className="mb-4">
        {activity.module === 'music' && (
          <MusicActivityContent activity={activity} />
        )}
        {activity.module === 'events' && (
          <EventActivityContent activity={activity} />
        )}
        {activity.module === 'store' && (
          <StoreActivityContent activity={activity} />
        )}
        {activity.module === 'loyalty' && (
          <LoyaltyActivityContent activity={activity} />
        )}
        {/* Add more module-specific renderers */}
      </div>
      
      {/* Engagement Actions */}
      <div className="flex items-center gap-4 border-t pt-4">
        <Button
          variant="ghost"
          size="sm"
          onClick={handleLike}
          className={liked ? 'text-red-500' : ''}
        >
          <Heart className={liked ? 'fill-current' : ''} />
          <span className="ml-2">{likesCount}</span>
        </Button>
        
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setShowComments(!showComments)}
        >
          <MessageCircle />
          <span className="ml-2">{activity.comments_count}</span>
        </Button>
        
        <Button variant="ghost" size="sm">
          <Share2 />
        </Button>
        
        <Button variant="ghost" size="sm" className="ml-auto">
          <Bookmark />
        </Button>
      </div>
      
      {/* Comments Section */}
      {showComments && (
        <CommentSection
          commentableType={activity.subject_type.toLowerCase().split('\\').pop()}
          commentableId={activity.subject_id}
        />
      )}
    </Card>
  );
}
```

**Component: `components/edula/CommentSection.tsx`** (Reusable)

```typescript
'use client';

import { useState, useEffect } from 'react';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Heart, Reply } from 'lucide-react';
import { socialApi } from '@/lib/api/social';

interface CommentSectionProps {
  commentableType: string;
  commentableId: number;
}

export function CommentSection({ commentableType, commentableId }: CommentSectionProps) {
  const [comments, setComments] = useState([]);
  const [newComment, setNewComment] = useState('');
  const [loading, setLoading] = useState(false);
  
  useEffect(() => {
    loadComments();
  }, []);
  
  const loadComments = async () => {
    const response = await socialApi.getComments(commentableType, commentableId);
    setComments(response.data.comments);
  };
  
  const handleSubmit = async () => {
    if (!newComment.trim()) return;
    
    setLoading(true);
    try {
      await socialApi.createComment({
        commentable_type: commentableType,
        commentable_id: commentableId,
        content: newComment,
      });
      setNewComment('');
      await loadComments();
    } finally {
      setLoading(false);
    }
  };
  
  return (
    <div className="mt-4 border-t pt-4 space-y-4">
      {/* Add Comment */}
      <div className="flex gap-3">
        <Avatar className="w-8 h-8" />
        <div className="flex-1">
          <Textarea
            placeholder="Write a comment..."
            value={newComment}
            onChange={(e) => setNewComment(e.target.value)}
            rows={2}
          />
          <Button
            onClick={handleSubmit}
            disabled={loading || !newComment.trim()}
            className="mt-2"
            size="sm"
          >
            Comment
          </Button>
        </div>
      </div>
      
      {/* Comments List */}
      <div className="space-y-3">
        {comments.map((comment) => (
          <CommentItem key={comment.id} comment={comment} />
        ))}
      </div>
    </div>
  );
}

function CommentItem({ comment }) {
  const [liked, setLiked] = useState(comment.is_liked);
  const [likesCount, setLikesCount] = useState(comment.likes_count);
  
  const handleLike = async () => {
    const result = await socialApi.toggleLike('comment', comment.id);
    setLiked(result.liked);
    setLikesCount(result.likes_count);
  };
  
  return (
    <div className="flex gap-3">
      <Avatar src={comment.user.avatar_url} className="w-8 h-8" />
      <div className="flex-1">
        <div className="bg-muted rounded-lg p-3">
          <p className="font-semibold text-sm">{comment.user.name}</p>
          <p className="text-sm mt-1">{comment.content}</p>
        </div>
        <div className="flex items-center gap-4 mt-2 text-xs text-muted-foreground">
          <button onClick={handleLike} className={liked ? 'text-red-500' : ''}>
            <Heart className="inline w-3 h-3" /> {likesCount}
          </button>
          <button>
            <Reply className="inline w-3 h-3" /> Reply
          </button>
          <span>{comment.created_at_human}</span>
        </div>
        
        {/* Nested replies */}
        {comment.replies && comment.replies.length > 0 && (
          <div className="ml-8 mt-3 space-y-3">
            {comment.replies.map((reply) => (
              <CommentItem key={reply.id} comment={reply} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
```

---

## 📋 Part 4: Implementation Checklist

### Phase 1: Database & Models (Week 1-2)

- [ ] Create `comments` table migration
- [ ] Verify/update `likes` table schema
- [ ] Create `user_follows` table migration
- [ ] Add new columns to `activities` table (module, priority, is_prestige, media_urls, engagement_score)
- [ ] Create `Comment` model (`app/Models/Comment.php`)
- [ ] Create `UserFollow` model (`app/Models/UserFollow.php`)
- [ ] Update `Activity` model with new fields
- [ ] Run migrations

### Phase 2: Traits & Services (Week 3-4)

- [ ] Create `HasComments` trait
- [ ] Create `HasLikes` trait
- [ ] Create `IsFollowable` trait
- [ ] Create `CreatesActivities` trait
- [ ] Update `ActivityService` with module-specific loggers
- [ ] Add traits to existing models (Song, Album, Event, Product, etc.)

### Phase 3: Universal API Endpoints (Week 5-6)

- [ ] Create `routes/api/social.php`
- [ ] Create `CommentController` (universal)
- [ ] Create `LikeController` (universal)
- [ ] Create `FollowController` (universal)
- [ ] Create `EdulaController` (replace FeedController routes)
- [ ] Test all endpoints with Postman/Insomnia

### Phase 4: Activity Observers (Week 7-8)

- [ ] Create observers for all modules to auto-log activities:
  - [ ] `SongObserver` → logs "uploaded_song"
  - [ ] `EventObserver` → logs "created_event"
  - [ ] `OrderObserver` → logs "placed_order"
  - [ ] `LoyaltyCardMemberObserver` → logs "joined_loyalty_card"
  - [ ] `OjokotauPledgeObserver` → logs "funded_campaign"
  - [ ] `AwardObserver` → logs "won_award", "nominated_for_award"
  - [ ] `PromotionOrderObserver` → logs "purchased_promotion"
  - [ ] `ForumPostObserver` → logs "created_forum_post"
  - [ ] `PollObserver` → logs "created_poll", "poll_closed"

### Phase 5: Next.js Frontend - Core Feed (Week 9-10)

- [ ] Create `/app/(main)/edula/page.tsx`
- [ ] Create `ActivityCard` component with module-specific renderers
- [ ] Create `CommentSection` component (reusable)
- [ ] Create `LikeButton` component (reusable)
- [ ] Create `FollowButton` component (reusable)
- [ ] Create API client (`lib/api/social.ts`)
- [ ] Implement infinite scroll
- [ ] Add tab navigation (For You, Following, Music, Events, etc.)

### Phase 6: Next.js Frontend - Social Widgets (Week 11-12)

- [ ] Create reusable `<Comments>` component (plug into any page)
- [ ] Create reusable `<LikeButton>` component (plug into any page)
- [ ] Create reusable `<FollowButton>` component (plug into any page)
- [ ] Add comments to Song detail page
- [ ] Add comments to Event detail page
- [ ] Add comments to Product detail page
- [ ] Add follow buttons to Artist profile
- [ ] Add follow buttons to Playlist detail
- [ ] Add follow buttons to Event detail

### Phase 7: Testing & Polish (Week 13-14)

- [ ] Unit tests for Comment model
- [ ] Unit tests for UserFollow model
- [ ] Integration tests for social APIs
- [ ] E2E tests for like/comment/follow flows
- [ ] Performance optimization (caching, lazy loading)
- [ ] Real-time updates (Pusher for new comments/likes)
- [ ] Deploy to staging
- [ ] User testing and feedback
- [ ] Bug fixes and refinements
- [ ] Deploy to production

---

## 🎯 Part 5: Expected Behavior After Implementation

### Edula Feed Will Show:

**Music Module:**
- "Artist X just uploaded new single 'Fire' 🔥"
- "Album 'Greatest Hits' by DJ Kiboko is now live"
- "Playlist 'Afrobeat Vibes' was updated with 10 new songs"

**Events Module:**
- "Jazz Night at Sheraton Hotel is happening tomorrow!"
- "Festival 2024 sold 50 tickets in the last hour"
- "User Y is attending Festival 2024"

**Store Module:**
- "User Z purchased 'Vintage Microphone' from Store X"
- "New product 'DJ Equipment Bundle' is now available"

**Loyalty Module:**
- "User A joined Gold tier of DJ Kiboko Fan Club"
- "50 fans joined Silver tier this week"
- "Exclusive track 'Unreleased Demo' was redeemed by 20 members"

**SACCO Module:**
- "Artist B applied for a production loan of 500,000 UGX"
- "User C saved 100,000 UGX towards 'Studio Equipment' goal"

**Ojokotau Module:**
- "Campaign 'Help Artist Build Studio' reached 75% funding"
- "User D backed 'New Music Video' with 50,000 UGX"

**Awards Module:**
- "Artist E won Best Newcomer at Uganda Music Awards"
- "Voting is now open for Best Song of the Year"
- "Artist F was nominated for 3 awards"

**Forum Module:**
- "New discussion: 'Best Ugandan producers of 2024?'"
- "Poll: 'Which genre is most popular?' closed with 500 votes"

**Promotions Module:**
- "Artist G purchased TikTok Live promotion"
- "DJ H's promotion service got 5-star review"

---

## 📊 Part 6: Success Metrics

**Engagement Metrics:**
- Average comments per activity: Target 2-5
- Like rate: Target 10-15% of viewers
- Follow conversion rate: Target 5% of profile visitors
- Daily active users on Edula: Target 30% of registered users

**Technical Metrics:**
- API response time: < 200ms for feed load
- Comment submission latency: < 500ms
- Like/follow action latency: < 300ms
- Feed cache hit rate: > 80%

**User Satisfaction:**
- Feed relevance score: Target 4.0+ / 5.0
- "Not Interested" click rate: Target < 5%
- Time spent on Edula: Target 10+ minutes per session

---

## 🚀 Quick Start for Copilot

**Step 1: Run migrations**
```bash
php artisan make:migration create_comments_table
php artisan make:migration create_user_follows_table
php artisan make:migration add_module_fields_to_activities_table
php artisan migrate
```

**Step 2: Create models**
```bash
php artisan make:model Comment
php artisan make:model UserFollow
```

**Step 3: Create traits**
```bash
mkdir -p app/Traits
touch app/Traits/HasComments.php
touch app/Traits/HasLikes.php
touch app/Traits/IsFollowable.php
touch app/Traits/CreatesActivities.php
```

**Step 4: Create controllers**
```bash
php artisan make:controller Api/Social/CommentController --api
php artisan make:controller Api/Social/LikeController --api
php artisan make:controller Api/Social/FollowController --api
php artisan make:controller Frontend/EdulaController
```

**Step 5: Add traits to models**
```php
// In Song.php, Album.php, Event.php, Product.php, etc.
use App\Traits\HasComments;
use App\Traits\HasLikes;

class Song extends Model
{
    use HasComments, HasLikes;
}
```

**Step 6: Register observers**
```php
// In AppServiceProvider.php boot() method
Song::observe(SongObserver::class);
Event::observe(EventObserver::class);
Order::observe(OrderObserver::class);
// ... register all module observers
```

**Step 7: Test with Postman**
```
POST /api/social/like/song/1
POST /api/social/comments (body: {commentable_type: 'song', commentable_id: 1, content: 'Great song!'})
POST /api/social/follow/artist/5
GET /edula/api/feed?tab=for_you
```

---

## ✅ Conclusion

This comprehensive audit reveals that TesoTunes has **solid foundations** for social features but they are:
1. **Fragmented** - No unified system
2. **Incomplete** - Missing models (Comment, UserFollow)
3. **Misaligned** - Edula behaves like Twitter instead of aggregated activity feed

The proposed solution creates a **pluggable, reusable social infrastructure** that ANY module can use with minimal code (just add a trait). The result is a cohesive platform where every action creates meaningful activity in Edula, and every piece of content can be commented on, liked, and followed.

**Implementation Priority:** HIGH - This is core infrastructure that affects all features.

**Estimated Timeline:** 14 weeks (3.5 months)

**Team:** 2-3 developers (1 backend, 1 frontend, 0.5 QA)

---

**Document Version:** 1.0  
**Date:** February 10, 2024  
**Status:** ✅ Comprehensive Audit Complete - Ready for Implementation