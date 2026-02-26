# Edula - Community Hub & Discovery Feed Documentation

**Platform**: TesoTunes Music Platform  
**Feature**: Edula (Community Hub / Discovery Feed)  
**URL**: `tesotunes.com/edula`  
**Status**: Production Ready ✅  
**Last Updated**: January 2025

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Frontend Routes & Pages](#frontend-routes--pages)
4. [Backend API Endpoints](#backend-api-endpoints)
5. [Mobile API Support](#mobile-api-support)
6. [Feed Service](#feed-service)
7. [Social Features](#social-features)
8. [User Interface Components](#user-interface-components)
9. [Database Models](#database-models)
10. [Integration Guide](#integration-guide)
11. [React Native Implementation](#react-native-implementation)

---

## Overview

**Edula** is TesoTunes' community hub and personalized discovery feed that provides users with a unified experience for:

- **Personalized Content Discovery** - Algorithm-driven recommendations
- **Social Interactions** - Posts, likes, comments, and sharing
- **Activity Feeds** - Following artists, friends, and platform events
- **Community Engagement** - Forum discussions, polls, and user-generated content
- **Music Discovery** - New releases from followed artists and trending content

### Key Features

✅ **For You Feed** - Personalized content based on user preferences  
✅ **Social Posts** - Share music, thoughts, and media with the community  
✅ **Artist Following** - Stay updated with new releases and activities  
✅ **Friend Activity** - See what friends are listening to and sharing  
✅ **Platform Events** - Announcements, featured content, and promotions  
✅ **Forum Integration** - Community discussions and topics  
✅ **Poll Participation** - Vote on community polls  
✅ **Recommendations** - AI-driven music recommendations  
✅ **Mobile Optimized** - Full support for React Native mobile apps  
✅ **Offline Capable** - Sync and cache for offline viewing

---

## Architecture

### System Components

```
┌─────────────────────────────────────────────────────────────┐
│                        EDULA SYSTEM                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐       │
│  │   Frontend  │  │  Mobile API │  │   Backend   │       │
│  │   (Blade)   │  │  (React N.) │  │  Services   │       │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘       │
│         │                │                 │               │
│         └────────────────┴─────────────────┘               │
│                          │                                 │
│                ┌─────────▼─────────┐                       │
│                │  EdulaController  │                       │
│                └─────────┬─────────┘                       │
│                          │                                 │
│         ┌────────────────┼────────────────┐               │
│         │                │                │               │
│    ┌────▼────┐    ┌─────▼─────┐    ┌────▼────┐          │
│    │  Feed   │    │   Social  │    │ Activity│          │
│    │ Service │    │  Service  │    │ Service │          │
│    └────┬────┘    └─────┬─────┘    └────┬────┘          │
│         │                │                │               │
│    ┌────▼────────────────▼────────────────▼────┐         │
│    │         Database (MySQL/SQLite)           │         │
│    │  - feed_items      - activities           │         │
│    │  - posts           - follows               │         │
│    │  - comments        - likes                 │         │
│    │  - forum_posts     - polls                 │         │
│    └───────────────────────────────────────────┘         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Technology Stack

- **Backend**: Laravel PHP Framework
- **Frontend**: Blade Templates + Alpine.js/Livewire
- **Mobile**: React Native (iOS/Android)
- **Database**: MySQL/MariaDB
- **Cache**: Redis (optional)
- **Authentication**: Laravel Sanctum (token-based)
- **API**: RESTful JSON APIs

---

## Frontend Routes & Pages

### Main Edula Route

**File**: `routes/frontend/public.php`

```php
// Edula (Community Hub) - Main "For You" Feed
Route::get('/edula', [EdulaController::class, 'index'])->name('edula');
Route::get('/edula/api/feed', [EdulaController::class, 'getFeed'])->name('edula.api.feed');
Route::post('/edula/api/refresh', [EdulaController::class, 'refresh'])->name('edula.refresh');
Route::post('/edula/api/items/{uuid}/not-interested', [EdulaController::class, 'notInterested'])
    ->name('edula.not-interested');
Route::post('/edula/api/items/{uuid}/save', [EdulaController::class, 'saveItem'])
    ->name('edula.save');
Route::post('/edula/api/items/{uuid}/track', [EdulaController::class, 'trackInteraction'])
    ->name('edula.track');

// Backward compatibility: timeline as alias for edula
Route::get('/timeline', [EdulaController::class, 'index'])->name('timeline');

// Legacy feed check endpoint (keep for backward compat)
Route::get('/edula/feed', [SocialFeedController::class, 'checkNewPosts'])->name('edula.feed');
```

### Access Levels

- **Public Access**: ✅ Available to both guests and authenticated users
- **Guest Experience**: Limited feed with public content only
- **Authenticated Experience**: Personalized feed with social features

---

## Backend API Endpoints

### Edula Controller Endpoints

#### 1. Get Feed
```
GET /edula/api/feed
```

**Query Parameters:**
- `page` (int): Page number for pagination
- `per_page` (int): Items per page (default: 20)
- `filter` (string): Filter type (all, following, recommendations, etc.)

**Response:**
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "uuid": "abc123",
        "type": "song_release",
        "title": "New Song from Artist",
        "description": "Check out this new release",
        "image": "https://cdn.../artwork.jpg",
        "action_url": "/songs/123",
        "metadata": {...},
        "created_at": "2025-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total": 100,
      "per_page": 20
    }
  }
}
```

#### 2. Refresh Feed
```
POST /edula/api/refresh
```

**Response:**
```json
{
  "success": true,
  "message": "Feed refreshed successfully",
  "new_items_count": 5
}
```

#### 3. Mark Not Interested
```
POST /edula/api/items/{uuid}/not-interested
```

**Request Body:**
```json
{
  "reason": "not_relevant"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Preference saved"
}
```

#### 4. Save Item
```
POST /edula/api/items/{uuid}/save
```

**Response:**
```json
{
  "success": true,
  "message": "Item saved for later"
}
```

#### 5. Track Interaction
```
POST /edula/api/items/{uuid}/track
```

**Request Body:**
```json
{
  "action": "click|view|like|share",
  "duration": 1200
}
```

---

## Mobile API Support

### Mobile Social Controller

**File**: `app/Http/Controllers/Api/Mobile/MobileSocialController.php`

#### Get Social Feed
```
GET /api/mobile/social/feed
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "posts": [
      {
        "id": 123,
        "content": "Check out this song!",
        "media": [
          {
            "url": "https://cdn.../image.jpg",
            "type": "image"
          }
        ],
        "visibility": "public",
        "user": {
          "id": 1,
          "name": "John Doe",
          "avatar": "https://cdn.../avatar.jpg"
        },
        "song": {
          "id": 5,
          "title": "Amazing Song",
          "artist": "Cool Artist",
          "artwork": "https://cdn.../artwork.jpg"
        },
        "likes_count": 42,
        "comments_count": 15,
        "is_liked": false,
        "created_at": "2025-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total": 50,
      "per_page": 20
    }
  }
}
```

#### Create Post
```
POST /api/mobile/social/posts
Authorization: Bearer {token}
Content-Type: multipart/form-data
```

**Request Body:**
```
content: "Check out this amazing track!"
song_id: 123
visibility: public|followers|private
media[]: [image/video files]
```

**Response:**
```json
{
  "success": true,
  "message": "Post created successfully",
  "data": {
    "post": {...}
  }
}
```

#### Like/Unlike Post
```
POST /api/mobile/social/posts/{postId}/like
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "action": "liked",
    "likes_count": 43
  }
}
```

#### Get Comments
```
GET /api/mobile/social/posts/{postId}/comments
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "comments": [
      {
        "id": 1,
        "content": "Great song!",
        "user": {...},
        "created_at": "2025-01-15T10:35:00Z"
      }
    ]
  }
}
```

#### Add Comment
```
POST /api/mobile/social/posts/{postId}/comments
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "content": "Amazing track!"
}
```

#### Get Notifications
```
GET /api/mobile/social/notifications
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "notifications": [
      {
        "id": 1,
        "type": "post_like",
        "data": {
          "user": "John Doe",
          "post_id": 123
        },
        "read_at": null,
        "created_at": "2025-01-15T10:30:00Z"
      }
    ],
    "unread_count": 5
  }
}
```

---

## Feed Service

### FeedService Class

**File**: `app/Services/FeedService.php`

The FeedService is responsible for generating personalized feeds using the unified FeedItem model.

#### Key Methods

```php
// Generate feed for user
$feedService->forUser($user)
    ->withFollowedArtists()
    ->withFriendActivity()
    ->withRecommendations()
    ->withPlatformEvents()
    ->paginate($page);

// Get feed with filters
$feedService->forUser($user)
    ->perPage(20)
    ->withFollowedArtists()
    ->paginate(1);
```

#### Feed Types

1. **Followed Artists** - New releases and activities from followed artists
2. **Friend Activity** - Posts and interactions from friends
3. **Platform Events** - Announcements and featured content
4. **Forum Activity** - Recent forum posts and discussions
5. **Poll Activity** - Active polls and voting
6. **Recommendations** - AI-driven music recommendations

#### Caching

The FeedService implements caching for performance:

```php
// Cache duration: 5 minutes
Cache::remember("feed:user:{$userId}:page:{$page}", 300, function() {
    return $this->generateFeed();
});
```

---

## Social Features

### Post Management

#### Post Visibility Levels

- **public** - Visible to everyone
- **followers** - Visible to followers only
- **private** - Visible to user only

#### Media Attachments

Posts support multiple media types:
- Images (JPG, PNG, GIF)
- Videos (MP4, MOV)
- Up to 10 media files per post

#### Post Interactions

- **Like/Unlike** - Toggle like status
- **Comment** - Add comments to posts
- **Share** - Share posts with others
- **Report** - Report inappropriate content

### Notifications

#### Notification Types

- `post_like` - Someone liked your post
- `post_comment` - Someone commented on your post
- `follow` - Someone followed you
- `mention` - Someone mentioned you
- `artist_release` - Followed artist released new content

#### Notification Preferences

Users can control which notifications they receive through settings.

---

## User Interface Components

### Sidebar Navigation

**File**: `resources/views/frontend/components/sidebar.blade.php`

```blade
<!-- Edula / Community Hub (Public - All Users) -->
<a href="{{ route('frontend.edula') }}"
   class="nav-item {{ request()->routeIs('frontend.edula') ? 'active' : '' }}">
    <span class="material-icons-round icon-md">hub</span>
    <span class="font-medium">Edula</span>
</a>
```

### Mobile Bottom Navigation

**File**: `resources/views/frontend/partials/mobile-bottom-nav.blade.php`

```blade
<!-- Explore/Music -->
<a href="{{ route('frontend.edula') }}" 
   class="flex flex-col items-center justify-center flex-1 py-2 
          {{ request()->routeIs('frontend.edula') ? 'text-brand-green' : 'text-gray-600' }}">
    <span class="material-symbols-outlined text-2xl">explore</span>
    <span class="text-xs mt-0.5 font-medium">Explore</span>
</a>
```

### Header Links

**File**: `resources/views/components/app-partials/header.blade.php`

Multiple Edula entry points in the header:
- Quick access icon
- User menu item
- Mobile searchbar item

---

## Database Models

### FeedItem Model

**Table**: `feed_items`

```sql
CREATE TABLE feed_items (
    id BIGINT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE,
    user_id BIGINT NULL,
    type VARCHAR(50),
    feedable_type VARCHAR(255),
    feedable_id BIGINT,
    title VARCHAR(255),
    description TEXT,
    metadata JSON,
    visibility VARCHAR(20) DEFAULT 'public',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX (user_id),
    INDEX (type),
    INDEX (feedable_type, feedable_id),
    INDEX (created_at)
);
```

**Feed Item Types:**
- `song_release` - New song release
- `album_release` - New album release
- `artist_activity` - Artist post or update
- `friend_activity` - Friend's activity
- `platform_event` - Platform announcement
- `forum_post` - New forum discussion
- `poll` - Community poll
- `recommendation` - Personalized recommendation

### Post Model

**Table**: `posts`

```sql
CREATE TABLE posts (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    content TEXT,
    song_id BIGINT NULL,
    visibility VARCHAR(20) DEFAULT 'public',
    media JSON,
    likes_count INT DEFAULT 0,
    comments_count INT DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (song_id) REFERENCES songs(id)
);
```

### Activity Model

**Table**: `activities`

```sql
CREATE TABLE activities (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    type VARCHAR(50),
    subject_type VARCHAR(255),
    subject_id BIGINT,
    metadata JSON,
    created_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## Integration Guide

### Adding Edula to Your Page

#### 1. Add Navigation Link

```blade
<a href="{{ route('frontend.edula') }}" class="nav-link">
    Community Hub
</a>
```

#### 2. Check Route Access

```php
// In your controller
if (request()->routeIs('frontend.edula')) {
    // User is on Edula page
}
```

#### 3. Generate Feed Items

```php
use App\Services\FeedService;

$feedService = app(FeedService::class);
$feed = $feedService->forUser(auth()->user())
    ->withFollowedArtists()
    ->withRecommendations()
    ->paginate(1);
```

### Creating Custom Feed Sources

#### 1. Create FeedItem

```php
use App\Models\FeedItem;

FeedItem::create([
    'uuid' => Str::uuid(),
    'user_id' => $userId, // null for public
    'type' => 'custom_event',
    'feedable_type' => Song::class,
    'feedable_id' => $songId,
    'title' => 'New Song Release',
    'description' => 'Check out this new track',
    'metadata' => [
        'artist_name' => 'Artist Name',
        'artwork_url' => 'https://...',
    ],
    'visibility' => 'public',
]);
```

#### 2. Register with Feed Service

```php
// In FeedService.php
protected function getCustomEvents(): Collection
{
    return FeedItem::where('type', 'custom_event')
        ->where('created_at', '>=', now()->subDays(7))
        ->get();
}
```

---

## React Native Implementation

### Setup API Client

```javascript
// services/api.js
const API_BASE = 'https://your-api.com/api';

export async function apiCall(endpoint, options = {}) {
  const token = await AsyncStorage.getItem('auth_token');
  
  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      ...options.headers
    }
  });
  
  return await response.json();
}
```

### Social Feed Component

```javascript
// screens/EdulaFeedScreen.js
import React, { useState, useEffect } from 'react';
import { FlatList, RefreshControl } from 'react-native';
import { apiCall } from '../services/api';

export function EdulaFeedScreen() {
  const [feed, setFeed] = useState([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  
  useEffect(() => {
    loadFeed();
  }, []);
  
  async function loadFeed() {
    setLoading(true);
    try {
      const { data } = await apiCall('/mobile/social/feed');
      setFeed(data.posts);
    } catch (error) {
      console.error('Failed to load feed:', error);
    } finally {
      setLoading(false);
    }
  }
  
  async function onRefresh() {
    setRefreshing(true);
    await loadFeed();
    setRefreshing(false);
  }
  
  async function likePost(postId) {
    try {
      await apiCall(`/mobile/social/posts/${postId}/like`, {
        method: 'POST'
      });
      loadFeed(); // Refresh to show updated like count
    } catch (error) {
      console.error('Failed to like post:', error);
    }
  }
  
  return (
    <FlatList
      data={feed}
      renderItem={({ item }) => (
        <PostCard 
          post={item} 
          onLike={() => likePost(item.id)}
        />
      )}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }
    />
  );
}
```

### Create Post

```javascript
// screens/CreatePostScreen.js
import React, { useState } from 'react';
import { apiCall } from '../services/api';

export function CreatePostScreen({ navigation }) {
  const [content, setContent] = useState('');
  const [songId, setSongId] = useState(null);
  const [media, setMedia] = useState([]);
  
  async function createPost() {
    const formData = new FormData();
    formData.append('content', content);
    formData.append('song_id', songId);
    formData.append('visibility', 'public');
    
    media.forEach((file, index) => {
      formData.append(`media[${index}]`, {
        uri: file.uri,
        type: file.type,
        name: file.name
      });
    });
    
    try {
      await apiCall('/mobile/social/posts', {
        method: 'POST',
        body: formData,
        headers: {
          'Content-Type': 'multipart/form-data'
        }
      });
      
      navigation.goBack();
    } catch (error) {
      console.error('Failed to create post:', error);
    }
  }
  
  return (
    // UI components for creating post
  );
}
```

### Offline Support

```javascript
// services/offlineQueue.js
import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';

export async function queueOfflineAction(action) {
  const queue = JSON.parse(
    await AsyncStorage.getItem('offline_queue') || '[]'
  );
  
  queue.push({
    ...action,
    timestamp: new Date().toISOString()
  });
  
  await AsyncStorage.setItem('offline_queue', JSON.stringify(queue));
}

export async function processOfflineQueue() {
  const isOnline = await NetInfo.fetch().then(s => s.isConnected);
  if (!isOnline) return;
  
  const queue = JSON.parse(
    await AsyncStorage.getItem('offline_queue') || '[]'
  );
  
  for (const action of queue) {
    try {
      if (action.type === 'like_post') {
        await apiCall(`/mobile/social/posts/${action.postId}/like`, {
          method: 'POST'
        });
      } else if (action.type === 'create_post') {
        await apiCall('/mobile/social/posts', {
          method: 'POST',
          body: JSON.stringify(action.data)
        });
      }
      // Remove from queue after successful sync
    } catch (error) {
      console.error('Failed to sync action:', error);
    }
  }
  
  await AsyncStorage.setItem('offline_queue', '[]');
}
```

---

## Performance Optimization

### Caching Strategy

1. **Feed Cache**: 5 minutes
2. **User Cache**: 15 minutes
3. **Post Cache**: 10 minutes
4. **Notification Cache**: 3 minutes

### Database Indexes

```sql
-- Optimize feed queries
CREATE INDEX idx_feed_items_user_created ON feed_items(user_id, created_at DESC);
CREATE INDEX idx_feed_items_type ON feed_items(type);
CREATE INDEX idx_posts_user_created ON posts(user_id, created_at DESC);
CREATE INDEX idx_activities_user_created ON activities(user_id, created_at DESC);
```

### Eager Loading

```php
// Prevent N+1 queries
$posts = Post::with(['user', 'song.artist', 'likes', 'comments'])
    ->latest()
    ->paginate(20);
```

---

## Security Features

### Authentication

- ✅ Laravel Sanctum token-based authentication
- ✅ CSRF protection on all write operations
- ✅ Rate limiting (100 requests/minute)

### Authorization

- ✅ User can only edit/delete own posts
- ✅ Visibility controls (public/followers/private)
- ✅ Admin moderation capabilities

### Content Moderation

- ✅ Report inappropriate content
- ✅ Block users
- ✅ Hide posts
- ✅ Admin review queue

---

## Analytics & Tracking

### Track User Interactions

```php
// Track feed item interaction
POST /edula/api/items/{uuid}/track
{
  "action": "view",
  "duration": 1200
}
```

### Analytics Events

- `feed_view` - User viewed feed
- `item_click` - User clicked feed item
- `item_like` - User liked item
- `item_share` - User shared item
- `post_create` - User created post
- `comment_create` - User commented

---

## Troubleshooting

### Common Issues

#### Feed Not Loading

1. Check authentication token
2. Verify route is properly registered
3. Check database migrations are run
4. Clear cache: `php artisan cache:clear`

#### Posts Not Appearing

1. Check post visibility settings
2. Verify user has permission to view
3. Check follow relationships
4. Review feed filters

#### Mobile API Errors

1. Verify Sanctum token is valid
2. Check API route is registered in `routes/api/mobile.php`
3. Ensure proper headers are sent
4. Check rate limiting

---

## Future Enhancements

### Planned Features

- [ ] Stories/Reels feature
- [ ] Live streaming integration
- [ ] Direct messaging
- [ ] Group chats
- [ ] Advanced content filtering
- [ ] Machine learning recommendations
- [ ] Real-time notifications (WebSockets)
- [ ] Video posts support
- [ ] Audio posts/voice notes

---

## API Rate Limits

| Endpoint Type | Rate Limit | Window |
|--------------|------------|--------|
| Feed Loading | 60 req/min | Per user |
| Post Creation | 10 req/min | Per user |
| Like/Unlike | 100 req/min | Per user |
| Comments | 30 req/min | Per user |
| Search | 30 req/min | Per user |

---

## Support & Resources

### Documentation Files

- `MOBILE_API_DOCUMENTATION.md` - Complete mobile API reference
- `MOBILE_API_QUICK_REFERENCE.md` - Quick start guide
- `MOBILE_API_QUICK_START.md` - Getting started tutorial
- `MOBILE_API_IMPLEMENTATION_COMPLETE.md` - Full implementation details

### Code Locations

- **Controllers**: `app/Http/Controllers/Frontend/EdulaController.php`
- **Services**: `app/Services/FeedService.php`
- **Models**: `app/Models/FeedItem.php`, `app/Models/Post.php`
- **Routes**: `routes/frontend/public.php`, `routes/api/mobile.php`
- **Views**: `resources/views/frontend/edula/`

### Contact

For questions or support regarding Edula implementation, please contact the development team.

---

**Version**: 1.0  
**Status**: Production Ready ✅  
**Platform**: TesoTunes Music Platform  
**Feature**: Edula Community Hub