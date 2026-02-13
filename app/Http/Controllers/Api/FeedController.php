<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedItem;
use App\Models\UserFeedSetting;
use App\Services\FeedAnalyticsService;
use App\Services\FeedPreferenceService;
use App\Services\FeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json([
            'data' => $feed->items(),
            'meta' => [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
            ],
            'links' => [
                'next' => $feed->nextPageUrl(),
                'prev' => $feed->previousPageUrl(),
            ],
        ]);
    }

    /**
     * GET /api/feed/for-you — personalized feed
     */
    public function forYou(Request $request)
    {
        $user = $request->user();
        $page = $request->integer('page', 1);

        $feed = $this->feedService
            ->forUser($user)
            ->perPage($request->integer('per_page', 20))
            ->forYou()
            ->get($page);

        if ($user) {
            $this->analyticsService->trackView($user, 'for_you');
        }

        return response()->json([
            'data' => $feed->items(),
            'meta' => [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
            ],
            'links' => [
                'next' => $feed->nextPageUrl(),
                'prev' => $feed->previousPageUrl(),
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

        return response()->json([
            'data' => $feed->items(),
            'meta' => [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
            ],
            'links' => [
                'next' => $feed->nextPageUrl(),
                'prev' => $feed->previousPageUrl(),
            ],
        ]);
    }

    /**
     * GET /api/feed/module/{module} — module-specific feed (music, events, awards, etc.)
     */
    public function module(Request $request, string $module)
    {
        $validModules = ['music', 'events', 'awards', 'store', 'ojokotau', 'sacco', 'loyalty', 'forum'];

        if (!in_array($module, $validModules)) {
            return response()->json(['message' => 'Invalid module.'], 422);
        }

        $user = $request->user();
        $page = $request->integer('page', 1);

        $feed = $this->feedService
            ->forUser($user)
            ->perPage($request->integer('per_page', 20))
            ->forModules([$module])
            ->get($page);

        return response()->json([
            'data' => $feed->items(),
            'meta' => [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
                'module' => $module,
            ],
            'links' => [
                'next' => $feed->nextPageUrl(),
                'prev' => $feed->previousPageUrl(),
            ],
        ]);
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
     * GET /api/feed/following — feed from followed artists/users
     */
    public function following(Request $request)
    {
        $user = $request->user();
        $page = $request->integer('page', 1);

        $feed = $this->feedService
            ->forUser($user)
            ->perPage($request->integer('per_page', 20))
            ->following()
            ->get($page);

        $this->analyticsService->trackView($user, 'following');

        return response()->json([
            'data' => $feed->items(),
            'meta' => [
                'current_page' => $feed->currentPage(),
                'last_page' => $feed->lastPage(),
                'per_page' => $feed->perPage(),
                'total' => $feed->total(),
            ],
            'links' => [
                'next' => $feed->nextPageUrl(),
                'prev' => $feed->previousPageUrl(),
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
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'links' => [
                'next' => $items->nextPageUrl(),
                'prev' => $items->previousPageUrl(),
            ],
        ]);
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
}
