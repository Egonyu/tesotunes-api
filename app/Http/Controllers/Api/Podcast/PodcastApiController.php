<?php

namespace App\Http\Controllers\Api\Podcast;

use App\Http\Controllers\Controller;
use App\Http\Resources\PodcastEpisodeResource;
use App\Http\Resources\PodcastResource;
use App\Models\Podcast;
use App\Models\PodcastCategory;
use App\Models\PodcastEpisode;
use App\Services\Podcast\AnalyticsService;
use App\Services\Podcast\RssFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PodcastApiController extends Controller
{
    public function __construct(
        protected RssFeedService $rssFeedService,
        protected AnalyticsService $analyticsService
    ) {
        $this->middleware('auth:sanctum')->except([
            'index', 'show', 'episodes', 'rss', 'rssFeed',
            'search', 'trending', 'categories',
        ]);
        $this->middleware('throttle:streaming')->only(['play', 'download']);
    }

    /**
     * Get all published podcasts.
     *
     * GET /api/podcasts?search=&category_id=&sort=latest|popular|trending&per_page=20
     */
    public function index(Request $request)
    {
        $query = Podcast::published()->with(['creator', 'category']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $sort = $request->get('sort', 'latest');
        match ($sort) {
            'popular' => $query->orderByDesc('total_listen_count'),
            'trending' => $query->orderByDesc('subscriber_count'),
            default => $query->latest('created_at'),
        };

        return PodcastResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * Get a specific podcast.
     *
     * GET /api/podcasts/{uuid}
     */
    public function show(string $uuid)
    {
        $podcast = Podcast::where('uuid', $uuid)
            ->with(['creator', 'category', 'subcategory'])
            ->firstOrFail();

        return new PodcastResource($podcast);
    }

    /**
     * Get episodes for a podcast.
     *
     * GET /api/podcasts/{uuid}/episodes?season=&sort=latest|oldest|popular
     */
    public function episodes(Request $request, string $uuid)
    {
        $podcast = Podcast::where('uuid', $uuid)->firstOrFail();

        $query = $podcast->episodes()->published();

        if ($request->filled('season')) {
            $query->where('season_number', $request->season);
        }

        $sort = $request->get('sort', 'latest');
        match ($sort) {
            'oldest' => $query->oldest('created_at'),
            'popular' => $query->orderByDesc('listen_count'),
            default => $query->latest('created_at'),
        };

        return PodcastEpisodeResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * Get RSS feed for a podcast (XML response).
     *
     * GET /api/podcasts/{uuid}/rss
     */
    public function rss(string $uuid): Response
    {
        $podcast = Podcast::where('uuid', $uuid)
            ->with(['episodes' => fn ($q) => $q->published()->latest('created_at')])
            ->firstOrFail();

        $rss = $this->rssFeedService->generate($podcast);

        return response($rss, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.(config('podcast.rss.ttl', 60) * 60),
        ]);
    }

    /** Alias used by route `rssFeed`. */
    public function rssFeed(string $uuid): Response
    {
        return $this->rss($uuid);
    }

    /**
     * Subscribe to a podcast.
     *
     * POST /api/podcasts/{uuid}/subscribe
     */
    public function subscribe(Request $request, string $uuid): JsonResponse
    {
        $podcast = Podcast::where('uuid', $uuid)->firstOrFail();
        $user = $request->user();

        $podcast->subscriptions()->firstOrCreate(['user_id' => $user->id]);

        return response()->json(['message' => 'Subscribed successfully.']);
    }

    /**
     * Unsubscribe from a podcast.
     *
     * DELETE /api/podcasts/{uuid}/unsubscribe
     */
    public function unsubscribe(Request $request, string $uuid): JsonResponse
    {
        $podcast = Podcast::where('uuid', $uuid)->firstOrFail();

        $podcast->subscriptions()->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Unsubscribed successfully.']);
    }

    /**
     * Search podcasts.
     *
     * GET /api/podcasts-search?q=
     */
    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:2']);

        $query = Podcast::published()
            ->with(['creator', 'category'])
            ->search($request->q);

        return PodcastResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * Get trending podcasts.
     *
     * GET /api/podcasts-trending
     */
    public function trending(Request $request)
    {
        $podcasts = Podcast::published()
            ->with(['creator', 'category'])
            ->orderByDesc('subscriber_count')
            ->limit($request->integer('limit', 20))
            ->get();

        return PodcastResource::collection($podcasts);
    }

    /**
     * Get podcast categories.
     *
     * GET /api/podcast-categories
     */
    public function categories()
    {
        $categories = PodcastCategory::withCount('podcasts')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug ?? null,
                'itunes_id' => $c->itunes_id ?? null,
                'podcast_count' => $c->podcasts_count,
            ]),
        ]);
    }

    /**
     * Get authenticated user's subscriptions.
     *
     * GET /api/my-podcast-subscriptions
     */
    public function mySubscriptions(Request $request)
    {
        $podcasts = Podcast::whereHas('subscriptions', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['creator', 'category'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return PodcastResource::collection($podcasts);
    }

    /**
     * Play an episode (track analytics).
     */
    public function play(Request $request, string $uuid): JsonResponse
    {
        $episode = PodcastEpisode::where('uuid', $uuid)
            ->with('podcast')
            ->firstOrFail();

        if ($episode->is_premium && ! $this->canAccessPremium($request->user(), $episode)) {
            return response()->json(['message' => 'Premium subscription required to access this episode.'], 403);
        }

        if ($request->user() && $request->user()->subscription_tier !== 'premium') {
            if ($this->analyticsService->hasExceededFreeLimit($request->user())) {
                return response()->json(['message' => 'You have reached your free episode limit for this month.'], 403);
            }
        }

        $listen = $this->analyticsService->trackListen($episode, [
            'user_id' => $request->user()?->id,
            'session_id' => $request->input('session_id', session()->getId()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'listen_duration' => $request->input('duration_seconds', 0),
            'started_at' => $request->input('started_at', now()),
            'last_position' => $request->input('position', 0),
            'device_type' => $this->detectDeviceType($request->userAgent()),
        ]);

        return response()->json([
            'data' => [
                'stream_url' => $episode->audio_url,
                'episode' => new PodcastEpisodeResource($episode),
                'listen_id' => $listen->id,
            ],
        ]);
    }

    /**
     * Download an episode.
     */
    public function download(Request $request, string $uuid): JsonResponse
    {
        $episode = PodcastEpisode::where('uuid', $uuid)
            ->with('podcast')
            ->firstOrFail();

        if ($episode->is_premium && ! $this->canAccessPremium($request->user(), $episode)) {
            return response()->json(['message' => 'Premium subscription required to download this episode.'], 403);
        }

        $this->analyticsService->trackDownload($episode, [
            'user_id' => $request->user()?->id,
            'quality' => $request->input('quality', 'medium'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => [
                'download_url' => $episode->audio_url,
                'file_size' => $episode->file_size,
                'mime_type' => $episode->mime_type,
            ],
        ]);
    }

    /**
     * Get user's listening history.
     */
    public function history(Request $request): JsonResponse
    {
        $history = $this->analyticsService->getUserListeningHistory(
            $request->user(),
            $request->integer('limit', 20)
        );

        return response()->json(['data' => $history]);
    }

    protected function canAccessPremium(?object $user, PodcastEpisode $episode): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->subscription_tier === 'premium') {
            return true;
        }

        if ($episode->podcast->isOwnedBy($user)) {
            return true;
        }

        return false;
    }

    protected function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/mobile|android|iphone/i', $userAgent)) {
            return 'mobile';
        }

        if (preg_match('/tablet|ipad/i', $userAgent)) {
            return 'tablet';
        }

        return 'desktop';
    }
}
