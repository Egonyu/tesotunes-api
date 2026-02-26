# TesoTunes Social Systems Master Rebuild Prompt - AI Copilot Implementation Guide

## 🎯 Executive Mission

You are tasked with rebuilding TesoTunes' social infrastructure to solve **THREE CRITICAL PROBLEMS**:

1. **Edula Feed is Broken** - Currently looks like Twitter/Facebook with user posts, but should aggregate activities from ALL platform modules
2. **Comments Are Fragmented** - No universal Comment model, inconsistent implementation across modules
3. **Likes/Follows Are Incomplete** - Referenced models don't exist, no reusable system

**End Goal:** Create a pluggable social infrastructure where ANY module can add comments, likes, follows, and auto-generate Edula activities with minimal code.

---

## 🔴 Critical Problems Identified

### Problem 1: Edula Feed Misalignment

**CURRENT (WRONG):**
- Edula shows user-generated posts (Twitter-style)
- Feed is populated by manual user posts with photos/videos
- System activities from modules are MISSING

**EXPECTED (CORRECT):**
- Edula is an **aggregated activity stream** from ALL modules
- Automatic activities when:
  - Song uploaded → "Artist X uploaded 'Fire'"
  - Event created → "Festival 2024 tickets on sale now!"
  - Order placed → "User Y bought 'Vintage Mic' from Store Z"
  - Loyalty member joins → "User A joined Gold tier of DJ Kiboko Fan Club"
  - SACCO loan approved → "Artist B got production loan approved"
  - Award won → "Artist C won Best Newcomer Award 🏆"
  - Campaign funded → "Campaign 'Build Studio' reached 75%"
  - Promotion purchased → "Artist D bought TikTok Live promotion"

**ROOT CAUSE:**
- `Activity` model exists but modules don't auto-create activities
- No observers/listeners logging activities when actions occur
- `FeedService` defines modules but doesn't fetch activities from them

### Problem 2: Missing Core Models

**Models Referenced But DON'T EXIST:**
1. `App\Models\Comment` - Controllers use it, but file doesn't exist
2. `App\Models\UserFollow` - User model expects it, but file doesn't exist

**Models That DO EXIST:**
- `App\Models\PostComment` - Only for Posts, not universal
- `App\Models\Like` - Universal, works well ✅
- `App\Models\Activity` - Exists but incomplete ✅

### Problem 3: No Unified Social System

**Current State:**
- Song, Album, Artist, Playlist have `comments()` relationships (good)
- Event, Product, LoyaltyCard DON'T have comments (bad)
- No reusable trait - each model implements manually
- No standard way to add "commentable", "likeable", "followable" to new models

---

## 🏗️ SOLUTION: Unified Social Infrastructure

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    ANY MODEL (Song, Event, Product, etc.)    │
│                                                              │
│  Add these traits:                                          │
│  - use HasComments;      → Instant commenting               │
│  - use HasLikes;         → Instant like/unlike              │
│  - use IsFollowable;     → Instant follow/unfollow          │
│  - use CreatesActivities; → Auto-logs to Edula feed         │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│              Universal Polymorphic Tables                    │
│                                                              │
│  comments (commentable_type, commentable_id)                │
│  likes (likeable_type, likeable_id)                         │
│  user_follows (followable_type, followable_id)              │
│  activities (subject_type, subject_id, module)              │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│              Next.js Reusable Components                     │
│                                                              │
│  <CommentSection type="song" id={123} />                    │
│  <LikeButton type="event" id={456} />                       │
│  <FollowButton type="artist" id={789} />                    │
└─────────────────────────────────────────────────────────────┘
```

---

## 📋 IMPLEMENTATION PHASES

## PHASE 1: Database Foundation (Week 1-2)

### Task 1.1: Create Comments Table

**Command:**
```bash
php artisan make:migration create_comments_table
```

**Migration Code:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Polymorphic
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Threading
            $table->foreignId('parent_id')->nullable()->constrained('comments')->onDelete('cascade');
            $table->unsignedInteger('depth')->default(0);
            
            // Content
            $table->text('content');
            
            // Moderation
            $table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])->default('approved');
            
            // Engagement
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            
            // Features
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['commentable_type', 'commentable_id']);
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
```

### Task 1.2: Create User Follows Table

**Command:**
```bash
php artisan make:migration create_user_follows_table
```

**Migration Code:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->onDelete('cascade');
            
            // Polymorphic
            $table->string('followable_type');
            $table->unsignedBigInteger('followable_id');
            
            $table->string('type', 50)->default('user');
            $table->boolean('notification_enabled')->default(true);
            
            $table->timestamp('created_at')->useCurrent();
            
            $table->unique(['follower_id', 'followable_type', 'followable_id'], 'unique_follow');
            $table->index(['followable_type', 'followable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_follows');
    }
};
```

### Task 1.3: Enhance Activities Table

**Command:**
```bash
php artisan make:migration add_module_fields_to_activities_table
```

**Migration Code:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('module', 50)->after('activity_type')->nullable()->index();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium')->index();
            $table->boolean('is_prestige')->default(false)->index();
            $table->json('media_urls')->nullable();
            $table->decimal('engagement_score', 10, 2)->default(0)->index();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['module', 'priority', 'is_prestige', 'media_urls', 'engagement_score']);
        });
    }
};
```

**Run:**
```bash
php artisan migrate
```

---

## PHASE 2: Create Core Models (Week 2)

### Task 2.1: Create Comment Model

**File:** `app/Models/Comment.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Comment extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'uuid', 'commentable_type', 'commentable_id', 'user_id',
        'parent_id', 'depth', 'content', 'status', 'likes_count',
        'replies_count', 'is_pinned', 'is_edited', 'edited_at'
    ];
    
    protected $casts = [
        'is_pinned' => 'boolean',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
    ];
    
    protected static function boot()
    {
        parent::boot();
        static::creating(fn($c) => $c->uuid = $c->uuid ?? (string) Str::uuid());
    }
    
    public function commentable(): MorphTo { return $this->morphTo(); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function parent(): BelongsTo { return $this->belongsTo(Comment::class, 'parent_id'); }
    public function replies(): HasMany { return $this->hasMany(Comment::class, 'parent_id'); }
    public function likes() { return $this->morphMany(Like::class, 'likeable'); }
    
    public function scopeApproved($q) { return $q->where('status', 'approved'); }
    public function scopeTopLevel($q) { return $q->whereNull('parent_id'); }
}
```

### Task 2.2: Create UserFollow Model

**File:** `app/Models/UserFollow.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class UserFollow extends Model
{
    const UPDATED_AT = null;
    
    protected $fillable = ['follower_id', 'followable_type', 'followable_id', 'type', 'notification_enabled'];
    protected $casts = ['notification_enabled' => 'boolean'];
    
    public function follower(): BelongsTo { return $this->belongsTo(User::class, 'follower_id'); }
    public function followable(): MorphTo { return $this->morphTo(); }
    
    public static function toggle(User $follower, Model $followable, string $type = 'user'): bool
    {
        $existing = static::where('follower_id', $follower->id)
            ->where('followable_type', get_class($followable))
            ->where('followable_id', $followable->id)
            ->first();
        
        if ($existing) {
            $existing->delete();
            if (method_exists($followable, 'decrement')) {
                $followable->decrement('followers_count');
            }
            return false; // Unfollowed
        }
        
        static::create([
            'follower_id' => $follower->id,
            'followable_type' => get_class($followable),
            'followable_id' => $followable->id,
            'type' => $type,
        ]);
        
        if (method_exists($followable, 'increment')) {
            $followable->increment('followers_count');
        }
        
        return true; // Followed
    }
}
```

---

## PHASE 3: Create Reusable Traits (Week 3)

### Task 3.1: HasComments Trait

**File:** `app/Traits/HasComments.php`

```php
<?php

namespace App\Traits;

use App\Models\{Comment, User};
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')
            ->whereNull('parent_id')
            ->latest();
    }
    
    public function allComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
    
    public function approvedComments(): MorphMany
    {
        return $this->comments()->where('status', 'approved');
    }
    
    public function getCommentsCountAttribute(): int
    {
        return $this->comments_count ?? $this->comments()->count();
    }
    
    public function hasCommentedBy(User $user): bool
    {
        return $this->allComments()->where('user_id', $user->id)->exists();
    }
}
```

### Task 3.2: HasLikes Trait

**File:** `app/Traits/HasLikes.php`

```php
<?php

namespace App\Traits;

use App\Models\{Like, User};
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasLikes
{
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable')->where('type', 'like');
    }
    
    public function getLikesCountAttribute(): int
    {
        return $this->likes_count ?? $this->likes()->count();
    }
    
    public function isLikedBy(User $user): bool
    {
        return $this->likes()->where('user_id', $user->id)->exists();
    }
    
    public function toggleLike(User $user): bool
    {
        return Like::toggle($user, $this);
    }
}
```

### Task 3.3: IsFollowable Trait

**File:** `app/Traits/IsFollowable.php`

```php
<?php

namespace App\Traits;

use App\Models\{UserFollow, User};
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait IsFollowable
{
    public function followers(): MorphMany
    {
        return $this->morphMany(UserFollow::class, 'followable');
    }
    
    public function getFollowersCountAttribute(): int
    {
        return $this->followers_count ?? $this->followers()->count();
    }
    
    public function isFollowedBy(User $user): bool
    {
        return $this->followers()->where('follower_id', $user->id)->exists();
    }
}
```

### Task 3.4: CreatesActivities Trait

**File:** `app/Traits/CreatesActivities.php`

```php
<?php

namespace App\Traits;

use App\Services\ActivityService;

trait CreatesActivities
{
    public static function bootCreatesActivities()
    {
        static::created(function ($model) {
            if (method_exists($model, 'shouldCreateActivity') && !$model->shouldCreateActivity()) {
                return;
            }
            ActivityService::logModelCreated($model);
        });
    }
    
    abstract public function getActivityModule(): string;
    
    public function getActivityType(): string
    {
        return 'created_' . strtolower(class_basename($this));
    }
    
    public function shouldCreateActivity(): bool
    {
        return true;
    }
}
```

---

## PHASE 4: Apply Traits to Existing Models (Week 3-4)

### Models to Update

**Song.php:**
```php
use App\Traits\{HasComments, HasLikes, CreatesActivities};

class Song extends Model
{
    use HasComments, HasLikes, CreatesActivities;
    
    public function getActivityModule(): string { return 'music'; }
}
```

**Event.php:**
```php
use App\Traits\{HasComments, HasLikes, IsFollowable, CreatesActivities};

class Event extends Model
{
    use HasComments, HasLikes, IsFollowable, CreatesActivities;
    
    public function getActivityModule(): string { return 'events'; }
}
```

**Store\Product.php:**
```php
use App\Traits\{HasComments, HasLikes};

class Product extends Model
{
    use HasComments, HasLikes;
}
```

**Store\Order.php:**
```php
use App\Traits\CreatesActivities;

class Order extends Model
{
    use CreatesActivities;
    
    public function getActivityModule(): string { return 'store'; }
}
```

**Loyalty\LoyaltyCard.php:**
```php
use App\Traits\{HasComments, IsFollowable, CreatesActivities};

class LoyaltyCard extends Model
{
    use HasComments, IsFollowable, CreatesActivities;
    
    public function getActivityModule(): string { return 'loyalty'; }
}
```

**Loyalty\LoyaltyCardMember.php:**
```php
use App\Traits\CreatesActivities;

class LoyaltyCardMember extends Model
{
    use CreatesActivities;
    
    public function getActivityModule(): string { return 'loyalty'; }
    
    public function getActivityType(): string { return 'joined_loyalty_card'; }
}
```

**Ojokotau\Campaign.php:**
```php
use App\Traits\{HasComments, IsFollowable, CreatesActivities};

class OjokotauCampaign extends Model
{
    use HasComments, IsFollowable, CreatesActivities;
    
    public function getActivityModule(): string { return 'ojokotau'; }
}
```

**Award.php, AwardNomination.php:**
```php
use App\Traits\CreatesActivities;

class Award extends Model
{
    use CreatesActivities;
    
    public function getActivityModule(): string { return 'awards'; }
}
```

---

## PHASE 5: Enhanced ActivityService (Week 4)

### Task 5.1: Update ActivityService

**File:** `app/Services/ActivityService.php`

```php
<?php

namespace App\Services;

use App\Models\{Activity, User};
use Illuminate\Database\Eloquent\Model;

class ActivityService
{
    /**
     * Log activity when any model is created
     */
    public static function logModelCreated(Model $model, User $actor = null): ?Activity
    {
        if (!$actor) {
            $actor = $model->user ?? $model->creator ?? $model->organizer ?? auth()->user();
        }
        
        if (!$actor) return null;
        
        return Activity::create([
            'user_id' => $actor->id,
            'type' => $model->getActivityType(),
            'activity_type' => strtolower(class_basename($model)),
            'subject_type' => get_class($model),
            'subject_id' => $model->id,
            'module' => $model->getActivityModule(),
            'priority' => self::getPriorityForModel($model),
            'is_prestige' => self::isPrestige($model),
            'data' => self::getActivityData($model),
            'media_urls' => self::getMediaUrls($model),
            'visibility' => $model->visibility ?? 'public',
            'created_at' => now(),
        ]);
    }
    
    protected static function getModuleForModel(Model $model): string
    {
        if (method_exists($model, 'getActivityModule')) {
            return $model->getActivityModule();
        }
        
        $map = [
            'Song' => 'music', 'Album' => 'music', 'Playlist' => 'music',
            'Event' => 'events', 'EventTicket' => 'events',
            'Order' => 'store', 'Product' => 'store',
            'LoyaltyCard' => 'loyalty', 'LoyaltyCardMember' => 'loyalty',
            'SaccoLoan' => 'sacco', 'SaccoMember' => 'sacco',
            'OjokotauCampaign' => 'ojokotau', 'OjokotauPledge' => 'ojokotau',
            'Award' => 'awards', 'AwardNomination' => 'awards',
            'Promotion' => 'promotions', 'PromotionOrder' => 'promotions',
        ];
        
        return $map[class_basename($model)] ?? 'general';
    }
    
    protected static function getPriorityForModel(Model $model): string
    {
        $high = ['Award', 'Event', 'OjokotauCampaign'];
        $medium = ['Song', 'Album', 'Order', 'LoyaltyCard'];
        
        return in_array(class_basename($model), $high) ? 'high' : 
               (in_array(class_basename($model), $medium) ? 'medium' : 'low');
    }
    
    protected static function isPrestige(Model $model): bool
    {
        return in_array(class_basename($model), ['Award', 'AwardNomination', 'Milestone']);
    }
    
    protected static function getActivityData(Model $model): array
    {
        return [
            'title' => $model->title ?? $model->name ?? null,
            'description' => $model->description ?? null,
            'price' => $model->price ?? $model->price_ugx ?? null,
        ];
    }
    
    protected static function getMediaUrls(Model $model): array
    {
        $urls = [];
        if (isset($model->artwork)) $urls[] = $model->artwork;
        if (isset($model->banner)) $urls[] = $model->banner;
        return array_filter($urls);
    }
}
```

---

## PHASE 6: Universal API Controllers (Week 5-6)

### Task 6.1: Comment Controller

**File:** `app/Http/Controllers/Api/Social/CommentController.php`

```php
<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\{Request, JsonResponse};

class CommentController extends Controller
{
    public function index(string $type, int $id): JsonResponse
    {
        $model = 'App\\Models\\' . ucfirst($type);
        if (!class_exists($model)) {
            return response()->json(['error' => 'Invalid type'], 400);
        }
        
        $comments = Comment::where('commentable_type', $model)
            ->where('commentable_id', $id)
            ->approved()
            ->topLevel()
            ->with(['user', 'replies.user'])
            ->paginate(20);
        
        return response()->json(['success' => true, 'comments' => $comments]);
    }
    
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|integer',
            'content' => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:comments,id',
        ]);
        
        $comment = Comment::create([
            'user_id' => auth()->id(),
            'commentable_type' => 'App\\Models\\' . ucfirst($validated['commentable_type']),
            'commentable_id' => $validated['commentable_id'],
            'parent_id' => $validated['parent_id'] ?? null,
            'content' => $validated['content'],
            'status' => 'approved',
        ]);
        
        return response()->json(['success' => true, 'comment' => $comment->load('user')], 201);
    }
    
    public function destroy(int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        
        if ($comment->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $comment->delete();
        return response()->json(['success' => true]);
    }
}
```

### Task 6.2: Like Controller

**File:** `app/Http/Controllers/Api/Social/LikeController.php`

```php
<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Like;
use Illuminate\Http\{Request, JsonResponse};

class LikeController extends Controller
{
    public function toggle(string $type, int $id): JsonResponse
    {
        $model = 'App\\Models\\' . ucfirst($type);
        if (!class_exists($model)) {
            return response()->json(['error' => 'Invalid type'], 400);
        }
        
        $likeable = $model::findOrFail($id);
        $isLiked = Like::toggle(auth()->user(), $likeable);
        
        return response()->json([
            'success' => true,
            'liked' => $isLiked,
            'likes_count' => $likeable->fresh()->likes_count ?? $likeable->likes()->count(),
        ]);
    }
}
```

### Task 6.3: Follow Controller

**File:** `app/Http/Controllers/Api/Social/FollowController.php`

```php
<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\UserFollow;
use Illuminate\Http\{Request, JsonResponse};

class FollowController extends Controller
{
    public function toggle(string $type, int $id): JsonResponse
    {
        $model = 'App\\Models\\' . ucfirst($type);
        if (!class_exists($model)) {
            return response()->json(['error' => 'Invalid type'], 400);
        }
        
        $followable = $model::findOrFail($id);
        $isFollowing = UserFollow::toggle(auth()->user(), $followable);
        
        return response()->json([
            'success' => true,
            'following' => $isFollowing,
            'followers_count' => $followable->fresh()->followers_count ?? $followable->followers()->count(),
        ]);
    }
}
```

---

## PHASE 7: API Routes (Week 6)

### Task 7.1: Create Social Routes File

**File:** `routes/api/social.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Social\{CommentController, LikeController, FollowController};

Route::middleware(['auth:sanctum'])->prefix('social')->group(function () {
    // Comments: /api/social/comments/{type}/{id}
    Route::get('comments/{type}/{id}', [CommentController::class, 'index']);
    Route::post('comments', [CommentController::class, 'store']);
    Route::delete('comments/{id}', [CommentController::class, 'destroy']);
    
    // Likes: /api/social/like/{type}/{id}
    Route::post('like/{type}/{id}', [LikeController::class, 'toggle']);
    
    // Follows: /api/social/follow/{type}/{id}
    Route::post('follow/{type}/{id}', [FollowController::class, 'toggle']);
});
```

**Add to `routes/api.php`:**
```php
require __DIR__ . '/api/social.php';
```

---

## PHASE 8: Next.js Reusable Components (Week 7-8)

### Component 1: CommentSection (Universal)

**File:** `components/social/CommentSection.tsx`

```typescript
'use client';

import { useState, useEffect } from 'react';
import { Avatar, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { socialApi } from '@/lib/api/social';

interface CommentSectionProps {
  commentableType: string; // 'song', 'event', 'product', etc.
  commentableId: number;
}

export function CommentSection({ commentableType, commentableId }: CommentSectionProps) {
  const [comments, setComments] = useState([]);
  const [newComment, setNewComment] = useState('');
  
  useEffect(() => {
    loadComments();
  }, []);
  
  const loadComments = async () => {
    const res = await socialApi.getComments(commentableType, commentableId);
    setComments(res.data.comments.data);
  };
  
  const handleSubmit = async () => {
    if (!newComment.trim()) return;
    
    await socialApi.createComment({
      commentable_type: commentableType,
      commentable_id: commentableId,
      content: newComment,
    });
    
    setNewComment('');
    await loadComments();
  };
  
  return (
    <div className="space-y-4">
      <div className="flex gap-3">
        <Avatar className="w-10 h-10" />
        <div className="flex-1">
          <Textarea
            placeholder="Write a comment..."
            value={newComment}
            onChange={(e) => setNewComment(e.target.value)}
          />
          <Button onClick={handleSubmit} className="mt-2">Comment</Button>
        </div>
      </div>
      
      <div className="space-y-3">
        {comments.map((c) => (
          <div key={c.id} className="flex gap-3">
            <Avatar className="w-8 h-8">
              <AvatarImage src={c.user.avatar_url} />
            </Avatar>
            <div className="bg-muted rounded-lg p-3 flex-1">
              <p className="font-semibold text-sm">{c.user.name}</p>
              <p className="text-sm">{c.content}</p>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
```

### Component 2: LikeButton

**File:** `components/social/LikeButton.tsx`

```typescript
'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Heart } from 'lucide-react';
import { socialApi } from '@/lib/api/social';

interface LikeButtonProps {
  type: string;
  id: number;
  initialLiked?: boolean;
  initialCount?: number;
}

export function LikeButton({ type, id, initialLiked, initialCount }: LikeButtonProps) {
  const [liked, setLiked] = useState(initialLiked || false);
  const [count, setCount] = useState(initialCount || 0);
  
  const handleLike = async () => {
    const result = await socialApi.toggleLike(type, id);
    setLiked(result.liked);
    setCount(result.likes_count);
  };
  
  return (
    <Button variant="ghost" onClick={handleLike} className={liked ? 'text-red-500' : ''}>
      <Heart className={liked ? 'fill-current' : ''} />
      <span className="ml-2">{count}</span>
    </Button>
  );
}
```

### Component 3: FollowButton

**File:** `components/social/FollowButton.tsx`

```typescript
'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { socialApi } from '@/lib/api/social';

interface FollowButtonProps {
  type: string;
  id: number;
  initialFollowing?: boolean;
}

export function FollowButton({ type, id, initialFollowing }: FollowButtonProps) {
  const [following, setFollowing] = useState(initialFollowing || false);
  
  const handleFollow = async () => {
    const result = await socialApi.toggleFollow(type, id);
    setFollowing(result.following);
  };
  
  return (
    <Button variant={following ? 'outline' : 'default'} onClick={handleFollow}>
      {following ? 'Following' : 'Follow'}
    </Button>
  );
}
```

### API Client

**File:** `lib/api/social.ts`

```typescript
import { apiClient } from './client';

export const socialApi = {
  // Comments
  getComments: (type: string, id: number) => 
    apiClient.get(`/social/comments/${type}/${id}`),
  
  createComment: (data: { commentable_type: string; commentable_id: number; content: string; parent_id?: number }) =>
    apiClient.post('/social/comments', data),
  
  deleteComment: (id: number) =>
    apiClient.delete(`/social/comments/${id}`),
  
  // Likes
  toggleLike: (type: string, id: number) =>
    apiClient.post(`/social/like/${type}/${id}`),
  
  // Follows
  toggleFollow: (type: string, id: number) =>
    apiClient.post(`/social/follow/${type}/${id}`),
};
```

---

## PHASE 9: Edula Feed Rebuild (Week 9-10)

### Task 9.1: Update Activity Model

**Add to `app/Models/Activity.php`:**

```php
// Add to $fillable
'module', 'priority', 'is_prestige', 'media_urls', 'engagement_score'

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
```

### Task 9.2: Create Edula Controller

**File:** `app/Http/Controllers/Frontend/EdulaController.php`

```php
<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\FeedService;
use Illuminate\Http\{Request, JsonResponse};

class EdulaController extends Controller
{
    public function __construct(protected FeedService $feedService) {}
    
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            return $this->getFeed($request);
        }
        return view('frontend.edula.index');
    }
    
    public function getFeed(Request $request): JsonResponse
    {
        $tab = $request->get('tab', 'for_you');
        $page = $request->get('page', 1);
        
        $builder = $this->feedService->forUser(auth()->user());
        
        switch ($tab) {
            case 'following': $builder->following(); break;
            case 'music': $builder->music(); break;
            case 'events': $builder->events(); break;
            case 'awards': $builder->awards(); break;
            default: $builder->forYou();
        }
        
        $feed = $builder->paginate($page);
        
        return response()->json([
            'success' => true,
            'feed' => [
                'items' => $feed->items(),
                'pagination' => [
                    'current_page' => $feed->currentPage(),
                    'has_more' => $feed->hasMorePages(),
                ],
            ],
        ]);
    }
}
```

---

## USAGE EXAMPLES

### Example 1: Add Comments to Song Detail Page

```typescript
// app/songs/[id]/page.tsx
import { CommentSection } from '@/components/social/CommentSection';
import { LikeButton } from '@/components/social/LikeButton';

export default function SongPage({ params }) {
  return (
    <div>
      <h1>Song Title</h1>
      <LikeButton type="song" id={params.id} />
      <CommentSection commentableType="song" commentableId={params.id} />
    </div>
  );
}
```

### Example 2: Add Follow to Event Page

```typescript
// app/events/[id]/page.tsx
import { FollowButton } from '@/components/social/FollowButton';
import { CommentSection } from '@/components/social/CommentSection';

export default function EventPage({ params }) {
  return (
    <div>
      <h1>Event Name</h1>
      <FollowButton type="event" id={params.id} />
      <CommentSection commentableType="event" commentableId={params.id} />
    </div>
  );
}
```

---

## ✅ SUCCESS CRITERIA

After implementation, verify:

- [ ] `Comment` model file exists at `app/Models/Comment.php`
- [ ] `UserFollow` model file exists at `app/Models/UserFollow.php`
- [ ] Traits work: Add `use HasComments;` to any model → comments work
- [ ] Universal API works: `POST /api/social/comments` accepts any type
- [ ] Next.js components work: `<CommentSection type="song" id={1} />` renders
- [ ] Activities auto-create: Upload song → Activity appears in Edula
- [ ] Edula aggregates all modules: Shows music, events, store, SACCO, loyalty activities

**Test Commands:**
```bash
# Backend
php artisan migrate
php artisan test --filter=Social

# Test in Postman
POST /api/social/comments {"commentable_type":"song","commentable_id":1,"content":"Test"}
POST /api/social/like/song/1
POST /api/social/follow/artist/5
```

---

## 📦 DELIVERABLES

### Backend (Laravel)
1. ✅ 3 migrations (comments, user_follows, activities enhancement)
2. ✅ 2 models (Comment, UserFollow)
3. ✅ 3 traits (HasComments, HasLikes, IsFollowable)
4. ✅ 3 API controllers (CommentController, LikeController, FollowController)
5. ✅ Updated ActivityService
6. ✅ API routes file

### Frontend (Next.js)
1. ✅ 3 reusable components (CommentSection, LikeButton, FollowButton)
2. ✅ API client (`lib/api/social.ts`)
3. ✅ TypeScript types
4. ✅ Usage examples

---

## 🎯 IMPLEMENTATION PRIORITY

**Priority 1 (Critical - Week 1-2):**
- Create Comment and UserFollow models
- Create traits
- Run migrations

**Priority 2 (High - Week 3-4):**
- Apply traits to all models
- Create universal API controllers
- Set up routes

**Priority 3 (Medium - Week 5-6):**
- Build Next.js components
- Integrate into existing pages
- Test end-to-end

**Priority 4 (Low - Week 7-8):**
- Activity auto-generation from observers
- Edula feed enhancement
- Analytics and optimization

---

## 🚀 START HERE - Quick Implementation Steps

```bash
# Step 1: Create migrations
php artisan make:migration create_comments_table
php artisan make:migration create_user_follows_table
php artisan make:migration add_module_fields_to_activities_table

# Step 2: Create models
mkdir -p app/Models
cat > app/Models/Comment.php << 'EOF'
[Copy Comment model code from Phase 2.1]
EOF

cat > app/Models/UserFollow.php << 'EOF'
[Copy UserFollow model code from Phase 2.2]
EOF

# Step 3: Create traits
mkdir -p app/Traits
cat > app/Traits/HasComments.php << 'EOF'
[Copy HasComments trait code from Phase 3.1]
EOF

# Step 4: Run migrations
php artisan migrate

# Step 5: Test
php artisan tinker
>>> $song = \App\Models\Song::first();
>>> $song->comments()->create(['user_id' => 1, 'content' => 'Test comment', 'status' => 'approved']);
>>> $song->comments; // Should return comments
```

---

## 📖 Additional Resources

- **Full Audit Document**: See `EDULA_SOCIAL_SYSTEMS_AUDIT_AND_REBUILD.md`
- **Laravel Polymorphic Relations**: https://laravel.com/docs/eloquent-relationships#polymorphic-relationships
- **Next.js App Router**: https://nextjs.org/docs/app
- **Shadcn UI Components**: https://ui.shadcn.com

---

## 🎬 FINAL CHECKLIST BEFORE MARKING COMPLETE

- [ ] `Comment.php` model created and working
- [ ] `UserFollow.php` model created and working
- [ ] All 3 traits created (HasComments, HasLikes, IsFollowable)
- [ ] Traits applied to at least 5 models (Song, Event, Product, Artist, LoyaltyCard)
- [ ] API routes working (`/api/social/comments`, `/api/social/like`, `/api/social/follow`)
- [ ] Next.js components created and reusable
- [ ] Tested comments on Song page
- [ ] Tested likes on Event page
- [ ] Tested follows on Artist profile
- [ ] Activities auto-generate when models are created
- [ ] Edula feed shows activities from all modules

---

**STATUS:** 🟢 READY FOR IMPLEMENTATION  
**ESTIMATED TIME:** 8-10 weeks  
**TEAM SIZE:** 2 developers (1 backend, 1 frontend)  
**PRIORITY:** CRITICAL - Core infrastructure

**START NOW:** Begin with Phase 1, Task 1.1 (Create comments migration)