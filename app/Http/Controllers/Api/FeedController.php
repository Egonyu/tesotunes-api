<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BaseResourceCollection;
use App\Models\FeedItem;
use App\Models\Post;
use App\Models\User;
use App\Models\UserFeedSetting;
use App\Models\UserFollow;
use App\Services\FeedAnalyticsService;
use App\Services\FeedPreferenceService;
use App\Services\FeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FeedController extends Controller
{
    public function __construct(
        protected FeedService $feedService,
        protected FeedPreferenceService $preferenceService,
        protected FeedAnalyticsService $analyticsService,
    ) {}

    /**
     * GET /api/feed — main feed (chronological/ranked)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $page = $request->integer('page', 1);

        $feed = $this->feedService
            ->forUser($user)
            ->perPage($request->integer('per_page', 20))
            ->forYou()
            ->get($page);

        if ($user) {
            $this->analyticsService->trackView($user, 'main');
        }

        return BaseResourceCollection::make($feed);
    }

    /**
     * GET /api/feed/for-you — personalized hybrid feed (user posts + system activities)
     *
     * Returns data in the Post shape the frontend expects, merging
     * user-created posts with system-generated FeedItems.
     */
    public function forYou(Request $request)
    {
        $user = $request->user();
        $perPage = $request->integer('per_page', 20);
        $page = $request->integer('page', 1);

        // 1. Get user posts
        $postsQuery = Post::with(['user', 'media', 'song.artist'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());

        if ($user) {
            $postsQuery->where(function ($q) use ($user) {
                $q->where('visibility', 'public')
                    ->orWhere('user_id', $user->id)
                    ->orWhere(function ($q2) use ($user) {
                        $q2->where('visibility', 'followers')
                            ->whereIn('user_id', UserFollow::where('follower_id', $user->id)->where('followable_type', User::class)->pluck('followable_id'));
                    });
            });
        } else {
            $postsQuery->where('visibility', 'public');
        }

        // 2. Merge with FeedItems (system activities)
        $feedItems = FeedItem::query()
            ->published()
            ->visible($user)
            ->latest('published_at')
            ->limit($perPage * 2)
            ->get();

        // 3. Transform posts to common shape
        $postItems = $postsQuery->latest('published_at')
            ->limit($perPage * 2)
            ->get()
            ->map(fn (Post $post) => $this->transformPostToFeedShape($post, $user));

        // 4. Transform feed items to post-compatible shape
        $feedItemsMapped = $feedItems->map(fn (FeedItem $item) => $this->transformFeedItemToPostShape($item, $user));

        // 5. Merge, sort by date, paginate
        $merged = $postItems->concat($feedItemsMapped)
            ->sortByDesc('created_at')
            ->values();

        $total = $merged->count();
        $offset = ($page - 1) * $perPage;
        $pageItems = $merged->slice($offset, $perPage)->values();

        // Weave platform-sponsored cards between organic items (clearly
        // labeled on the client; inventory from admin Featured Content).
        $pageItems = app(\App\Services\Feed\SponsoredSlotsService::class)
            ->injectInto($pageItems, $page);

        // Weave "Earn" translation task cards (Ateso corpus). No-ops unless the
        // contributions module + feed cards are enabled.
        $pageItems = app(\App\Modules\Contributions\Services\ContributionFeedSlotsService::class)
            ->injectInto($pageItems, $page, $user);

        if ($user) {
            $this->analyticsService->trackView($user, 'for_you');
        }

        return response()->json([
            'data' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * GET /api/feed/discover — discovery / trending feed
     */
    public function discover(Request $request)
    {
        $user = $request->user();
        $page = $request->integer('page', 1);

        $feed = $this->feedService
            ->forUser($user)
            ->perPage($request->integer('per_page', 20))
            ->discover()
            ->get($page);

        if ($user) {
            $this->analyticsService->trackView($user, 'discover');
        }

        return BaseResourceCollection::make($feed);
    }

    /**
     * GET /api/feed/module/{module} — module-specific feed (music, events, awards, etc.)
     */
    public function module(Request $request, string $module)
    {
        $validModules = ['music', 'events', 'awards', 'store', 'ojokotau', 'sacco', 'loyalty', 'forum'];

        if (! in_array($module, $validModules)) {
            return response()->json(['message' => 'Invalid module.'], 422);
        }

        $user = $request->user();
        $page = $request->integer('page', 1);

        $feed = $this->feedService
            ->forUser($user)
            ->perPage($request->integer('per_page', 20))
            ->forModules([$module])
            ->get($page);

        return new BaseResourceCollection($feed, ['module' => $module]);
    }

    /**
     * GET /api/feed/tabs — available feed tabs
     */
    public function tabs(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['key' => 'for-you', 'label' => 'For You', 'icon' => 'sparkles'],
                ['key' => 'discover', 'label' => 'Discover', 'icon' => 'compass'],
                ['key' => 'following', 'label' => 'Following', 'icon' => 'users'],
                ['key' => 'music', 'label' => 'Music', 'icon' => 'music'],
                ['key' => 'events', 'label' => 'Events', 'icon' => 'calendar'],
                ['key' => 'awards', 'label' => 'Awards', 'icon' => 'trophy'],
            ],
        ]);
    }

    /**
     * GET /api/feed/trending — trending items
     */
    public function trending(Request $request): JsonResponse
    {
        $period = $request->input('period', 'today');
        $limit = min($request->integer('limit', 20), 50);

        $query = \App\Models\FeedItem::query()
            ->published()
            ->where('visibility', 'public');

        // Period filter
        $query->where('published_at', '>=', match ($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            default => now()->subDay(),
        });

        $items = $query->orderByDesc('views_count')
            ->orderByDesc('likes_count')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => $item->toDTO($request->user()));

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * GET /api/feed/following — feed from followed artists/users (hybrid)
     */
    public function following(Request $request)
    {
        $user = $request->user();
        $perPage = $request->integer('per_page', 20);
        $page = $request->integer('page', 1);

        $followingIds = UserFollow::where('follower_id', $user->id)
            ->where('followable_type', User::class)
            ->pluck('followable_id');

        // Posts from followed users
        $posts = Post::with(['user', 'media', 'song.artist'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('user_id', $followingIds)
            ->where(function ($q) {
                $q->where('visibility', 'public')
                    ->orWhere('visibility', 'followers');
            })
            ->latest('published_at')
            ->limit($perPage * 2)
            ->get()
            ->map(fn (Post $post) => $this->transformPostToFeedShape($post, $user));

        // Feed items from followed actors
        $feedItems = FeedItem::query()
            ->published()
            ->visible($user)
            ->whereIn('actor_id', $followingIds)
            ->latest('published_at')
            ->limit($perPage * 2)
            ->get()
            ->map(fn (FeedItem $item) => $this->transformFeedItemToPostShape($item, $user));

        // Merge & paginate
        $merged = $posts->concat($feedItems)
            ->sortByDesc('created_at')
            ->values();

        $total = $merged->count();
        $offset = ($page - 1) * $perPage;
        $pageItems = $merged->slice($offset, $perPage)->values();

        $this->analyticsService->trackView($user, 'following');

        return response()->json([
            'data' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * GET /api/feed/saved — saved feed items
     */
    public function saved(Request $request)
    {
        $user = $request->user();

        $savedIds = $this->preferenceService->getSavedItemIds($user);

        if (empty($savedIds)) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
            ]);
        }

        $items = FeedItem::whereIn('id', $savedIds)
            ->latest()
            ->paginate($this->getPerPage($request));

        return BaseResourceCollection::make($items);
    }

    /**
     * GET /api/feed/{uuid} — single feed item
     */
    public function show(string $uuid)
    {
        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'data' => $item->toDTO(request()->user()),
        ]);
    }

    /**
     * POST /api/feed/{uuid}/not-interested — mark item as not interested
     */
    public function notInterested(Request $request, string $uuid): JsonResponse
    {
        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        $this->preferenceService->markNotInterestedItem(
            $request->user(),
            $item,
            $request->get('reason'),
        );

        return response()->json(['message' => 'Marked as not interested.']);
    }

    /**
     * POST /api/feed/{uuid}/hide — hide item from feed
     */
    public function hide(Request $request, string $uuid): JsonResponse
    {
        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        $this->preferenceService->hideItem($request->user(), $item);
        $this->analyticsService->trackHiddenItem($request->user(), $item->id);

        return response()->json(['message' => 'Item hidden.']);
    }

    /**
     * POST /api/feed/{uuid}/save — save item for later
     */
    public function save(Request $request, string $uuid): JsonResponse
    {
        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        $this->preferenceService->saveItem($request->user(), $item);

        return response()->json(['message' => 'Item saved.']);
    }

    /**
     * DELETE /api/feed/{uuid}/save — unsave item
     */
    public function unsave(Request $request, string $uuid): JsonResponse
    {
        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        $this->preferenceService->unsaveItem($request->user(), $item);

        return response()->json(['message' => 'Item unsaved.']);
    }

    /**
     * POST /api/feed/{uuid}/click — track item click
     */
    public function trackClick(Request $request, string $uuid): JsonResponse
    {
        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        $this->analyticsService->trackClickItem(
            $request->user(),
            $item->id,
            $request->get('tab', 'for_you'),
            $request->only(['source', 'position']),
        );

        return response()->json(['message' => 'Click tracked.']);
    }

    /**
     * POST /api/feed/{uuid}/engage — track engagement (like, share, etc.)
     */
    public function trackEngagement(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'event_type' => 'required|string|in:like,share,comment,play',
        ]);

        $item = FeedItem::where('uuid', $uuid)->firstOrFail();

        $this->analyticsService->trackEngagementItem(
            $request->user(),
            $item->id,
            $request->input('event_type'),
            $request->get('tab', 'for_you'),
            $request->only(['source']),
        );

        return response()->json(['message' => 'Engagement tracked.']);
    }

    /**
     * POST /api/feed/refresh — refresh/clear cached feed
     */
    public function refresh(Request $request): JsonResponse
    {
        $this->feedService
            ->forUser($request->user())
            ->clearCache();

        return response()->json(['message' => 'Feed refreshed.']);
    }

    /**
     * GET /api/announcements — platform announcements
     */
    public function announcements(Request $request): JsonResponse
    {
        $type = $request->input('type'); // info, warning, success, event

        $query = \App\Models\FeedItem::query()
            ->where('type', 'announcement')
            ->published()
            ->where('visibility', 'public')
            ->latest('published_at');

        if ($type) {
            $query->where('module', $type);
        }

        $announcements = $query->paginate($request->integer('per_page', 10));

        $announcements->through(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'content' => $item->body,
            'type' => $item->module ?? 'info',
            'link_url' => $item->extras['link_url'] ?? null,
            'link_text' => $item->extras['link_text'] ?? null,
            'image_url' => $item->media_url,
            'is_pinned' => $item->is_prestige,
            'created_at' => $item->published_at?->toIso8601String(),
            'expires_at' => $item->expires_at?->toIso8601String(),
        ]);

        return response()->json($announcements);
    }

    /**
     * GET /api/feed/preferences — get feed preferences
     */
    public function preferences(Request $request): JsonResponse
    {
        $prefs = $this->preferenceService->getUserPreferences($request->user());

        $settings = UserFeedSetting::where('user_id', $request->user()->id)->first();

        return response()->json([
            'data' => [
                'feedback_summary' => $prefs,
                'settings' => $settings?->preferences ?? [],
            ],
        ]);
    }

    /**
     * PUT /api/feed/preferences — update feed preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
        ]);

        UserFeedSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['preferences' => $validated['preferences']],
        );

        $this->preferenceService->updatePreferences($request->user(), $validated['preferences']);

        return response()->json(['message' => 'Feed preferences updated.']);
    }

    // ── Transformer Helpers ──────────────────────────────────────

    /**
     * Transform a Post model into the standard feed/post shape
     * that the frontend expects (matches TypeScript Post interface).
     */
    protected function transformPostToFeedShape(Post $post, ?\App\Models\User $viewer = null): array
    {
        $author = $post->user;

        return [
            'id' => $post->id,
            'uuid' => $post->uuid ?? Str::uuid()->toString(),
            'source' => 'post',
            'author' => [
                'id' => $author->id ?? 0,
                'name' => $author->name ?? 'Unknown',
                'username' => $author->username ?? $author->name ?? 'unknown',
                'avatar_url' => $author->avatar_url ?? $author->profile_photo_url ?? '',
                'is_verified' => (bool) ($author->is_verified ?? false),
            ],
            'content' => $post->content ?? '',
            'media' => $this->transformPostMedia($post),
            'visibility' => $post->visibility ?? 'public',
            'created_at' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'likes_count' => $post->likes_count ?? 0,
            'comments_count' => $post->comments_count ?? 0,
            'reposts_count' => $post->shares_count ?? 0,
            'views_count' => $post->views_count ?? 0,
            'is_liked' => $viewer ? $post->isLikedBy($viewer) : false,
            'is_reposted' => false,
            'is_bookmarked' => $viewer ? \App\Models\Like::where('user_id', $viewer->id)
                ->where('likeable_type', Post::class)
                ->where('likeable_id', $post->id)
                ->where('type', 'bookmark')
                ->exists() : false,
        ];
    }

    /**
     * Transform a FeedItem model into the standard post shape
     * that the frontend expects (matches TypeScript Post interface).
     */
    protected function transformFeedItemToPostShape(FeedItem $item, ?\App\Models\User $viewer = null): array
    {
        return [
            'id' => $item->id,
            'uuid' => $item->uuid ?? Str::uuid()->toString(),
            'source' => 'feed_item',
            'feed_type' => $item->type,
            'module' => $item->module,
            'author' => [
                'id' => $item->actor_id ?? 0,
                'name' => $item->actor_name ?? 'TesoTunes',
                'username' => $item->actor_name ?? 'tesotunes',
                'avatar_url' => $item->actor_avatar_url ?? '',
                'is_verified' => (bool) ($item->actor_verified ?? false),
            ],
            'content' => $item->body ?? $item->title ?? '',
            'title' => $item->title,
            'media' => $item->media_url ? [
                'type' => $item->media_type ?? 'image',
                'url' => $item->media_url,
                'thumbnail_url' => $item->media_thumbnail_url ?? $item->media_url,
            ] : null,
            'visibility' => $item->visibility ?? 'public',
            'created_at' => ($item->published_at ?? $item->created_at)?->toIso8601String(),
            'likes_count' => $item->likes_count ?? 0,
            'comments_count' => $item->comments_count ?? 0,
            'reposts_count' => $item->shares_count ?? 0,
            'views_count' => $item->views_count ?? 0,
            'is_liked' => $viewer ? \App\Models\Like::where('user_id', $viewer->id)
                ->where('likeable_type', FeedItem::class)
                ->where('likeable_id', $item->id)
                ->exists() : false,
            'is_reposted' => false,
            'is_bookmarked' => $viewer ? \App\Models\Like::where('user_id', $viewer->id)
                ->where('likeable_type', FeedItem::class)
                ->where('likeable_id', $item->id)
                ->where('type', 'bookmark')
                ->exists() : false,
            'is_prestige' => $item->is_prestige,
            'extras' => $item->extras,
            'actions' => $item->actions,
            'tags' => $item->tags,
        ];
    }

    /**
     * Transform post media (uploaded files or linked song).
     */
    protected function transformPostMedia(Post $post): ?array
    {
        $media = $post->media->first();
        if ($media) {
            return [
                'type' => $media->type,
                'url' => $media->url,
                'thumbnail_url' => $media->thumbnail_url,
            ];
        }

        if ($post->song) {
            return [
                'type' => 'song',
                'url' => $post->song->artwork_url,
                'thumbnail_url' => $post->song->artwork_url,
                'title' => $post->song->title,
                'artist' => $post->song->artist?->stage_name ?? $post->song->artist?->name ?? '',
                'song_id' => $post->song->id,
            ];
        }

        return null;
    }
}
