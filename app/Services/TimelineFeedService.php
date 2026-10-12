<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\FeedItem;
use App\Models\Modules\Forum\Poll;
use App\Models\Post;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFollow;
use App\Modules\Podcast\Models\PodcastEpisode;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Store\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TimelineFeedService
{
    public const MODULES = ['music', 'social', 'events', 'awards', 'store', 'forum', 'podcasts', 'promotions', 'platform'];

    private const CONTENT_TYPES = ['song_release', 'album_release', 'playlist_created', 'artist_update', 'artist_joined', 'event_created', 'event_reminder', 'award_won', 'award_season_started', 'store_created', 'product_listed', 'thread_created', 'poll_created', 'episode_published', 'promotion_request_posted', 'announcement'];

    private const DISCOVERY_TYPES = ['song_release', 'album_release', 'playlist_created', 'episode_published', 'poll_created', 'event_created', 'product_listed', 'store_created', 'promotion_request_posted'];

    public function __construct(private FeedRankingService $ranking) {}

    /**
     * A finite discovery session. Cursor order is frozen, but visibility and
     * subject availability are rechecked on every page. Only references are cached.
     *
     * @return array{items: Collection, meta: array, pulse: array}
     */
    public function get(?User $user, array $options): array
    {
        $view = $options['view'] ?? 'for-you';
        abort_if(in_array($view, ['saved', 'following'], true) && ! $user, 401);
        $preferences = app(TimelinePreferenceService::class);
        $state = $user ? $preferences->get($user) : ['interests' => [], 'saved' => [], 'hidden' => []];
        $interests = array_values(array_unique($options['interests'] ?? []));
        sort($interests);
        $module = $options['module'] ?? null;
        $context = hash('sha256', json_encode([$user?->id, $view, $module, $interests]));
        $offset = 0;
        $session = null;
        $token = Str::uuid()->toString();
        if (! empty($options['cursor'])) {
            try {
                [$token, $offset] = json_decode(Crypt::decryptString($options['cursor']), true, flags: JSON_THROW_ON_ERROR);
                $session = Cache::get('edula:timeline:'.$token);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cursor' => 'This feed cursor is invalid. Refresh the feed.']);
            }
            abort_unless($session && $session['context'] === $context, 409, 'This feed session has expired. Refresh the feed.');
            abort_unless(is_int($offset) && $offset >= 0, 422, 'Invalid feed offset.');
        }
        $at = $session ? Carbon::parse($session['at']) : now();
        $since = $at->copy()->subDays(30);
        $freshSince = $at->copy()->subHours(48);
        $allowed = $view === 'interests' ? $interests : self::MODULES;
        if ($module) {
            $allowed = array_values(array_intersect($allowed, [$module]));
        }

        $records = FeedItem::query()->published()->where('visibility', 'public')
            ->whereIn('module', $allowed)->whereIn('type', self::CONTENT_TYPES)
            ->when($view === 'saved', function ($query) use ($state) {
                $saved = $state['saved'];
                $query->where(function ($match) use ($saved) {
                    $match->whereIn('uuid', array_map(fn ($value) => substr($value, 5), array_filter($saved, fn ($value) => str_starts_with($value, 'feed:'))))
                        ->orWhere(fn ($songs) => $songs->where('type', 'song_release')->where('subject_type', Song::class)->whereIn('subject_id', array_map(fn ($value) => substr($value, 5), array_filter($saved, fn ($value) => str_starts_with($value, 'song:')))))
                        ->orWhere(fn ($episodes) => $episodes->where('subject_type', PodcastEpisode::class)->whereIn('subject_id', array_map(fn ($value) => substr($value, 8), array_filter($saved, fn ($value) => str_starts_with($value, 'episode:')))));
                });
            })
            ->when($view !== 'saved', fn ($query) => $query->whereBetween('published_at', [$since, $at]))->with('subject')
            ->orderByDesc('published_at')->orderByDesc('id')->limit($view === 'saved' ? 500 : 400)->get()
            ->loadMorph('subject', [Song::class => ['artist', 'primaryGenre'], PodcastEpisode::class => ['podcast'], Product::class => ['store']])
            ->filter(fn (FeedItem $item) => $this->isAvailable($item, $user))
            ->reject(fn (FeedItem $item) => in_array($preferences->identity($item), $state['hidden'], true))
            ->unique(fn (FeedItem $item) => $preferences->identity($item))->values();
        foreach ($records as $record) {
            if ($record->subject instanceof Poll && $record->subject->is_anonymous) {
                $record->actor_id = null;
            }
        }
        $follows = $user ? UserFollow::query()->where('follower_id', $user->id)->get(['followable_type', 'followable_id']) : collect();
        $followedUsers = $follows->where('followable_type', User::class)->pluck('followable_id');
        $followedArtists = $follows->where('followable_type', Artist::class)->pluck('followable_id');
        $followedCreators = Artist::query()->whereIn('id', $followedArtists)->whereIn('status', Artist::VISIBLE_STATUSES)->pluck('user_id');
        $followedActorIds = $followedUsers->merge($followedCreators)->unique();
        $isFollowed = fn (FeedItem $item) => $followedActorIds->contains($item->actor_id) || ($item->subject instanceof Song && $followedArtists->contains($item->subject->artist_id));
        if ($view === 'following') {
            $records = $records->filter($isFollowed)->values();
        } elseif ($view === 'saved') {
            $records = $records->filter(fn (FeedItem $item) => in_array($preferences->identity($item), $state['saved'], true))->values();
        }
        $scored = in_array($view, ['latest', 'saved'], true) ? $records : $this->ranking->rankFeedItems($user, $records);
        $ranked = $scored->map(fn (FeedItem $item) => [
            'key' => 'feed:'.$item->id, 'model' => $item, 'module' => $item->module,
            'actor' => $item->actor_id, 'at' => $item->published_at,
            'score' => ($item->feed_score ?? 0) + (in_array($item->module, $interests, true) ? 35 : 0) + ($isFollowed($item) ? 35 : 0),
            'section' => $item->published_at->gte($freshSince) ? 'recent' : 'discovery',
            'reason' => $isFollowed($item) ? 'From a creator you follow' : (in_array($item->module, $interests, true) ? 'From your selected interests' : 'Public activity across TesoTunes'),
        ]);

        if (in_array('social', $allowed, true)) {
            $posts = Post::query()->with(['user', 'media', 'song.artist'])
                ->when($view === 'saved', fn ($query) => $query->whereIn('id', array_map(fn ($value) => substr($value, 5), array_filter($state['saved'], fn ($value) => str_starts_with($value, 'post:')))))
                ->when($view === 'saved', fn ($query) => $query->where('published_at', '<=', $at), fn ($query) => $query->whereBetween('published_at', [$since, $at]))
                ->where(function ($query) use ($user, $view) {
                    $query->where('visibility', 'public');
                    if ($user && $view !== 'latest') {
                        $query->orWhere('user_id', $user->id)->orWhere(function ($followers) use ($user) {
                            $followers->where('visibility', 'followers')->whereIn('user_id', UserFollow::query()
                                ->where('follower_id', $user->id)->where('followable_type', User::class)->select('followable_id'));
                        });
                    }
                })->when($view === 'following', fn ($query) => $query->whereIn('user_id', $followedActorIds))
                ->orderByDesc('published_at')->orderByDesc('id')->limit($view === 'saved' ? 500 : 100)->get()
                ->reject(fn (Post $post) => in_array('post:'.$post->id, $state['hidden'], true))
                ->filter(fn (Post $post) => $view !== 'saved' || in_array('post:'.$post->id, $state['saved'], true));
            $ranked = $ranked->concat($posts->map(fn (Post $post) => [
                'key' => 'post:'.$post->id, 'model' => $post, 'module' => 'social', 'actor' => $post->user_id,
                'at' => $post->published_at, 'score' => 45 + max(0, 48 - $post->published_at->diffInHours($at)) + (in_array('social', $interests, true) ? 35 : 0),
                'section' => $post->published_at->gte($freshSince) ? 'recent' : 'discovery', 'reason' => 'A community conversation',
            ]));
        }

        $recent = $ranked->filter(fn (array $entry) => $entry['section'] === 'recent');
        if (($view === 'saved') || (! in_array($view, ['latest', 'following'], true) && $recent->count() < 6)) {
            $catalogue = $this->catalogDiscoveries($allowed, $at, $records, $user, $view === 'saved' ? $state['saved'] : null);
            $ranked = $ranked->concat($catalogue->reject(fn (array $entry) => in_array($preferences->identity($entry['model']), $state['hidden'], true)));
        }
        $byKey = $ranked->keyBy('key');
        if (! $session) {
            if (in_array($view, ['latest', 'saved'], true)) {
                $ordered = $ranked->sortByDesc(fn (array $entry) => $entry['at']->getTimestamp())->values();
                $ordered = $ordered->map(fn (array $entry) => array_replace($entry, ['section' => 'recent']));
            } else {
                $discovery = $ranked->filter(fn (array $entry) => $entry['section'] === 'discovery' && $entry['model'] instanceof FeedItem && in_array($entry['model']->type, self::DISCOVERY_TYPES, true));
                $selection = $discovery->sortByDesc('score')->take(24)->sortBy(fn (array $entry) => hash('sha256', $at->toDateString().':'.($user?->id ?? 'guest').':'.$entry['key']))->values();
                $ordered = $this->diversify($recent->sortByDesc('score')->values())
                    ->concat($this->diversify($selection)->take(8));
            }
            $session = ['context' => $context, 'at' => $at->toIso8601String(), 'order' => $ordered->map(fn (array $entry) => ['key' => $entry['key'], 'section' => $entry['section']])->values()->all()];
            Cache::put('edula:timeline:'.$token, $session, now()->addMinutes(15));
        }
        $size = (int) ($options['per_page'] ?? 15);
        $references = array_slice($session['order'], $offset, $size);
        $items = collect($references)->map(function (array $ref) use ($byKey) {
            $entry = $byKey->get($ref['key']);

            return $entry ? array_replace($entry, ['section' => $ref['section']]) : null;
        })->filter()->values();
        $nextOffset = $offset + count($references);
        $hasMore = $nextOffset < count($session['order']);

        return ['items' => $items, 'meta' => [
            'next_cursor' => $hasMore ? Crypt::encryptString(json_encode([$token, $nextOffset])) : null,
            'snapshot_at' => $session['at'], 'recent_count' => collect($session['order'])->where('section', 'recent')->count(),
            'discovery_count' => collect($session['order'])->where('section', 'discovery')->count(),
            'fresh_hours' => 48, 'window_days' => 30, 'window_limited' => $records->count() === 400,
        ], 'pulse' => $this->communityPulse($allowed, $at)];
    }

    /** Spread content without inventing activity or dropping eligible records. */
    public function diversify(Collection $entries): Collection
    {
        $result = collect();
        $remaining = $entries->values();
        while ($remaining->isNotEmpty()) {
            $last = $result->last();
            $pick = $remaining->search(fn (array $entry) => ! $last || ($entry['module'] !== $last['module'] && (! $entry['actor'] || $entry['actor'] !== $last['actor'])));
            $pick = $pick === false ? 0 : $pick;
            $result->push($remaining->get($pick));
            $remaining->forget($pick);
            $remaining = $remaining->values();
        }

        return $result;
    }

    public function isAvailable(FeedItem $item, ?User $viewer): bool
    {
        if (! $item->subject_type || ! $item->subject_id) {
            return true;
        }
        $subject = $item->subject;
        if (! $subject) {
            return false;
        }
        if ($subject instanceof Song) {
            return $subject->status === 'published' && $subject->visibility === 'public' && in_array($subject->artist?->status, Artist::VISIBLE_STATUSES, true) && (! $subject->published_at || $subject->published_at->lte(now()));
        }
        if ($subject instanceof PodcastEpisode) {
            return $subject->status === 'published' && $subject->published_at?->lte(now()) && $subject->podcast?->status === 'published';
        }
        if ($subject instanceof Poll) {
            return $subject->status === 'active' && (! $subject->ends_at || $subject->ends_at->gt(now())) && (! $subject->starts_at || $subject->starts_at->lte(now())) && ($subject->audience === 'all' || ($subject->audience === 'users' && $viewer));
        }
        if ($subject instanceof Event) {
            return $subject->status === 'published' && $subject->visibility === 'public' && ! $subject->hide_from_non_qualifying && $subject->ends_at?->gte(now());
        }
        if ($subject instanceof Product) {
            return $subject->status === 'active' && $subject->store?->status === 'active';
        }
        if ($subject instanceof PromotionRequest) {
            return $subject->status === 'open' && (! $subject->deadline_at || $subject->deadline_at->gt(now()));
        }
        if (array_key_exists('visibility', $subject->getAttributes()) && $subject->visibility !== 'public') {
            return false;
        }
        if (array_key_exists('is_public', $subject->getAttributes()) && ! $subject->is_public) {
            return false;
        }

        return ! in_array($subject->status, ['draft', 'pending', 'rejected', 'archived', 'cancelled', 'suspended', 'inactive', 'hidden'], true);
    }

    public function canDisplay(FeedItem $item, ?User $viewer): bool
    {
        return in_array($item->type, self::CONTENT_TYPES, true) && $item->visibility === 'public' && $this->isAvailable($item, $viewer);
    }

    private function catalogDiscoveries(array $allowed, Carbon $at, Collection $records, ?User $user, ?array $saved = null): Collection
    {
        $result = collect();
        if (in_array('music', $allowed, true)) {
            $songs = Song::query()->with(['artist', 'primaryGenre'])->published()->where('visibility', 'public')->whereHas('artist', fn ($query) => $query->whereIn('status', Artist::VISIBLE_STATUSES))
                ->when($saved !== null, fn ($query) => $query->whereIn('id', array_map(fn ($identity) => substr($identity, 5), array_filter($saved, fn ($identity) => str_starts_with($identity, 'song:')))))
                ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', $at))->where('created_at', '<=', $at)
                ->whereNotIn('id', $records->where('type', 'song_release')->where('subject_type', Song::class)->pluck('subject_id'))
                ->orderByRaw('COALESCE(published_at, created_at) DESC')->orderByDesc('id')->limit($saved !== null ? 500 : 24)->get();
            if ($saved === null) {
                $songs = $songs->sortBy(fn (Song $song) => hash('sha256', $at->toDateString().':'.($user?->id ?? 'guest').':song:'.$song->id))->take(4);
            }
            foreach ($songs as $song) {
                $date = $song->published_at ?? $song->created_at;
                $item = new FeedItem(['type' => 'song_release', 'module' => 'music', 'title' => $song->title, 'body' => 'From the TesoTunes music catalogue', 'actor_name' => $song->artist->stage_name, 'actor_type' => 'artist', 'actor_id' => $song->artist->user_id, 'media_type' => 'song', 'media_url' => $song->artwork_url, 'media_thumbnail_url' => $song->artwork_url, 'published_at' => $date, 'visibility' => 'public', 'extras' => ['catalog_discovery' => true, 'song_id' => $song->id, 'date_label' => $song->published_at ? 'Published' : 'Added to catalogue'], 'actions' => [['type' => 'play', 'label' => 'Play song', 'url' => '/songs/'.$song->slug]]]);
                $item->id = -$song->id;
                $item->uuid = 'catalog-song-'.$song->id;
                $item->setRelation('subject', $song);
                $result->push(['key' => $item->uuid, 'model' => $item, 'module' => 'music', 'actor' => $song->artist->user_id, 'at' => $date, 'score' => 0, 'section' => 'discovery', 'reason' => 'A catalogue release worth discovering']);
            }
        }
        if (in_array('podcasts', $allowed, true)) {
            $episodes = PodcastEpisode::query()->with('podcast')->where('status', 'published')->whereHas('podcast', fn ($query) => $query->where('status', 'published'))
                ->when($saved !== null, fn ($query) => $query->whereIn('id', array_map(fn ($identity) => substr($identity, 8), array_filter($saved, fn ($identity) => str_starts_with($identity, 'episode:')))))
                ->whereNotNull('published_at')->where('published_at', '<=', $at)->where('created_at', '<=', $at)
                ->whereNotIn('id', $records->where('subject_type', PodcastEpisode::class)->pluck('subject_id'))
                ->orderByDesc('published_at')->orderByDesc('id')->limit($saved !== null ? 500 : 24)->get();
            if ($saved === null) {
                $episodes = $episodes->sortBy(fn (PodcastEpisode $episode) => hash('sha256', $at->toDateString().':'.($user?->id ?? 'guest').':episode:'.$episode->id))->take(4);
            }
            foreach ($episodes as $episode) {
                $item = new FeedItem(['type' => 'episode_published', 'module' => 'podcasts', 'title' => $episode->title, 'body' => $episode->podcast->title, 'actor_name' => $episode->podcast->title, 'media_type' => 'image', 'media_url' => $episode->artwork ?? $episode->podcast->artwork, 'published_at' => $episode->published_at, 'visibility' => 'public', 'extras' => ['catalog_discovery' => true], 'actions' => [['type' => 'view', 'label' => 'Listen to episode', 'url' => '/podcasts/'.$episode->podcast_id.'/episodes/'.$episode->id]]]);
                $item->id = -$episode->id;
                $item->uuid = 'catalog-episode-'.$episode->id;
                $item->setRelation('subject', $episode);
                $result->push(['key' => $item->uuid, 'model' => $item, 'module' => 'podcasts', 'actor' => null, 'at' => $episode->published_at, 'score' => 0, 'section' => 'discovery', 'reason' => 'An earlier episode worth discovering']);
            }
        }

        return $result;
    }

    /** Only explicitly public records, with a minimum cohort; never raw buyer identities. */
    private function communityPulse(array $allowed, Carbon $at): array
    {
        $groups = FeedItem::query()->published()->where('visibility', 'public')->whereIn('module', $allowed)
            ->whereIn('type', ['song_played', 'product_purchased', 'ticket_purchased'])
            ->whereBetween('published_at', [$at->copy()->subDays(7), $at])->get(['type', 'actor_id'])->groupBy('type');

        return $groups->map(function (Collection $items, string $type) {
            $people = $items->pluck('actor_id')->filter()->unique()->count();

            return $people >= 3 ? ['type' => $type, 'count' => $people, 'period' => 'This week'] : null;
        })->filter()->values()->all();
    }
}
