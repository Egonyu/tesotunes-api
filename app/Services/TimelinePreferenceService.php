<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\FeedItem;
use App\Models\Post;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFeedSetting;
use App\Models\UserFollow;
use App\Modules\Podcast\Models\PodcastEpisode;
use Illuminate\Database\Eloquent\Model;

class TimelinePreferenceService
{
    public function get(User $user): array
    {
        $preferences = UserFeedSetting::query()->where('user_id', $user->id)->first()?->preferences ?? [];
        $state = is_array($preferences['_edula'] ?? null) ? $preferences['_edula'] : [];

        return [
            'interests' => array_values(array_intersect(array_filter(is_array($state['interests'] ?? null) ? $state['interests'] : ['music', 'podcasts'], 'is_string'), TimelineFeedService::MODULES)),
            'saved' => $this->canonicalValues($state['saved'] ?? []),
            'hidden' => $this->canonicalValues($state['hidden'] ?? []),
            'affinity' => $this->affinityState($state['affinity'] ?? []),
        ];
    }

    /** Steps a single "more" or "less" moves an affinity, and the most it can move in either direction. */
    public const AFFINITY_STEP = 1;

    public const AFFINITY_LIMIT = 3;

    /** Creators remembered per member; the oldest drop off first. */
    private const AFFINITY_ACTORS = 100;

    /**
     * @return array{modules: array<string, int>, actors: array<string, int>}
     */
    private function affinityState(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $clean = fn (mixed $map, callable $key) => collect(is_array($map) ? $map : [])
            ->filter(fn ($score, $name) => is_int($score) && $score !== 0 && $key((string) $name))
            ->map(fn (int $score) => max(-self::AFFINITY_LIMIT, min(self::AFFINITY_LIMIT, $score)))
            ->all();

        return [
            'modules' => $clean($value['modules'] ?? [], fn (string $name) => in_array($name, TimelineFeedService::MODULES, true)),
            'actors' => $clean($value['actors'] ?? [], fn (string $name) => ctype_digit($name)),
        ];
    }

    /**
     * The module and creator a piece of content belongs to, for affinity.
     * Anonymous polls and community corpus pairs never record a creator.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public function signal(Model $record): array
    {
        return match (true) {
            $record instanceof FeedItem => [$record->module, $record->actor_id ? (int) $record->actor_id : null],
            $record instanceof Song => ['music', $record->artist?->user_id],
            $record instanceof PodcastEpisode => ['podcasts', $record->podcast?->artist?->user_id],
            $record instanceof Post => ['social', $record->user_id],
            $record instanceof \App\Models\Event => ['events', $record->user_id ?? $record->organizer_id ?? null],
            $record instanceof \App\Models\Modules\Forum\Poll => ['forum', $record->is_anonymous ? null : $record->user_id],
            $record instanceof \App\Modules\Store\Models\Product => [$record->product_type === \App\Modules\Store\Models\Product::TYPE_PROMOTION ? 'promotions' : 'store', $record->store?->user_id],
            $record instanceof \App\Modules\Promotions\Models\PromotionRequest => ['promotions', $record->created_by_user_id],
            $record instanceof \App\Modules\Contributions\Models\CorpusPair => ['ateso', null],
            $record instanceof \App\Modules\Store\Models\Deal => ['store', $record->store?->user_id],
            default => [null, null],
        };
    }

    public function update(User $user, array $input): array
    {
        $signal = [null, null];
        if (in_array($input['action'] ?? null, ['save', 'hide', 'more', 'less', 'undo-less'], true)) {
            $record = $this->resolve($input['identity'], $user);
            // Undoing a "less" must work even if the item has since gone away.
            abort_unless($record || $input['action'] === 'undo-less', 404, 'This content is no longer available.');
            if ($record instanceof FeedItem) {
                $input['identity'] = $this->identity($record);
            }
            if ($record) {
                $signal = $this->signal($record);
            }
        }
        $user->getConnection()->transaction(function () use ($user, $input, $signal) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $setting = UserFeedSetting::query()->firstOrCreate(['user_id' => $user->id], ['preferences' => []]);
            $locked = UserFeedSetting::query()->whereKey($setting->id)->lockForUpdate()->firstOrFail();
            $preferences = $locked->preferences ?? [];
            $state = is_array($preferences['_edula'] ?? null) ? $preferences['_edula'] : ['interests' => ['music', 'podcasts'], 'saved' => [], 'hidden' => []];
            if (array_key_exists('interests', $input)) {
                $state['interests'] = array_values(array_unique($input['interests']));
            }
            if ($action = $input['action'] ?? null) {
                if ($action === 'restore-hidden') {
                    $state['hidden'] = [];
                    $preferences['_edula'] = $state;
                    $locked->update(['preferences' => $preferences]);

                    return;
                }
                if (in_array($action, ['more', 'less', 'undo-less'], true)) {
                    // "less" also hides the item; "undo-less" is its exact inverse.
                    $step = $action === 'less' ? -self::AFFINITY_STEP : self::AFFINITY_STEP;
                    $affinity = $this->affinityState($state['affinity'] ?? []);
                    [$module, $actor] = $signal;
                    if ($module && in_array($module, TimelineFeedService::MODULES, true)) {
                        $affinity['modules'][$module] = max(-self::AFFINITY_LIMIT, min(self::AFFINITY_LIMIT, ($affinity['modules'][$module] ?? 0) + $step));
                    }
                    if ($actor) {
                        $key = (string) $actor;
                        $score = max(-self::AFFINITY_LIMIT, min(self::AFFINITY_LIMIT, ($affinity['actors'][$key] ?? 0) + $step));
                        unset($affinity['actors'][$key]);
                        $affinity['actors'][$key] = $score;
                        $affinity['actors'] = array_slice($affinity['actors'], -self::AFFINITY_ACTORS, null, true);
                    }
                    $state['affinity'] = $this->affinityState($affinity);
                    if ($action !== 'more') {
                        $hidden = array_values(array_diff($this->canonicalValues($state['hidden'] ?? []), [$input['identity']]));
                        if ($action === 'less') {
                            abort_if(count($hidden) >= 500, 422, 'This collection is full. Remove an item before adding more.');
                            $hidden[] = $input['identity'];
                        }
                        $state['hidden'] = $hidden;
                    }
                    $preferences['_edula'] = $state;
                    $locked->update(['preferences' => $preferences]);

                    return;
                }
                $field = in_array($action, ['save', 'unsave'], true) ? 'saved' : 'hidden';
                $values = $this->canonicalValues($state[$field] ?? []);
                $identity = $input['identity'];
                $values = array_values(array_diff($values, [$identity]));
                if (in_array($action, ['save', 'hide'], true)) {
                    abort_if(count($values) >= 500, 422, 'This collection is full. Remove an item before adding more.');
                    $values[] = $identity;
                }
                $state[$field] = $values;
            }
            $preferences['_edula'] = $state;
            $locked->update(['preferences' => $preferences]);
        });

        return $this->get($user);
    }

    public function identity(FeedItem $item): string
    {
        if ($item->uuid === 'project-ateso') {
            return 'project:ateso';
        }
        $kind = match ($item->subject_type) {
            \App\Models\Event::class => 'event',
            \App\Models\Modules\Forum\Poll::class => 'poll',
            \App\Modules\Store\Models\Product::class => 'product',
            \App\Modules\Promotions\Models\PromotionRequest::class => 'brief',
            \App\Modules\Contributions\Models\CorpusPair::class => 'corpus',
            \App\Modules\Store\Models\Deal::class => 'deal',
            default => null,
        };
        if ($kind && $item->subject_id) {
            return $kind.':'.$item->subject_id;
        }
        if ($item->subject_type === Song::class && $item->type === 'song_release') {
            return 'song:'.$item->subject_id;
        }
        if ($item->subject_type === PodcastEpisode::class) {
            return 'episode:'.$item->subject_id;
        }
        if (str_starts_with($item->uuid, 'catalog-song-')) {
            return 'song:'.substr($item->uuid, strlen('catalog-song-'));
        }
        if (str_starts_with($item->uuid, 'catalog-episode-')) {
            return 'episode:'.substr($item->uuid, strlen('catalog-episode-'));
        }

        return 'feed:'.$item->uuid;
    }

    private function canonicalValues(mixed $values): array
    {
        $values = is_array($values) ? array_filter($values, 'is_string') : [];
        $uuids = array_map(fn ($value) => substr($value, 5), array_filter($values, fn ($value) => str_starts_with($value, 'feed:')));
        if (! $uuids) {
            return array_values(array_unique($values));
        }
        $aliases = FeedItem::query()->whereIn('uuid', $uuids)->get()->mapWithKeys(fn (FeedItem $item) => ['feed:'.$item->uuid => $this->identity($item)]);

        return array_values(array_unique(array_map(fn ($value) => $aliases->get($value, $value), $values)));
    }

    /** Whether the viewer may see the item this identity names right now. */
    public function available(string $identity, User $viewer): bool
    {
        return $this->resolve($identity, $viewer) !== null;
    }

    private function resolve(string $identity, User $viewer): ?Model
    {
        [$kind, $id] = explode(':', $identity, 2);
        $record = match ($kind) {
            'feed' => FeedItem::query()->published()->where('visibility', 'public')->where('uuid', $id)->with('subject')->first(),
            'song' => Song::query()->with('artist')->published()->where('visibility', 'public')->whereHas('artist', fn ($query) => $query->whereIn('status', Artist::VISIBLE_STATUSES))->find($id),
            'episode' => PodcastEpisode::query()->with('podcast')->where('status', 'published')->where('published_at', '<=', now())->whereHas('podcast', fn ($query) => $query->where('status', 'published'))->find($id),
            'post' => Post::query()->where('published_at', '<=', now())->find($id),
            'event', 'poll', 'product', 'brief', 'corpus' => app(TimelineCatalogueService::class)->available($kind, (int) $id, $viewer),
            'deal' => \App\Modules\Store\Models\Deal::query()->live()->find($id),
            'project' => $id === 'ateso' ? app(TimelineCatalogueService::class)->project(now()) : null,
            default => null,
        };
        if ($record instanceof FeedItem && ! app(TimelineFeedService::class)->canDisplay($record, $viewer)) {
            return null;
        }
        if ($record instanceof Song && $record->published_at?->isFuture()) {
            return null;
        }
        if ($record instanceof Post && $record->visibility !== 'public' && $record->user_id !== $viewer->id) {
            if ($record->visibility !== 'followers' || ! UserFollow::query()->where('follower_id', $viewer->id)->where('followable_type', User::class)->where('followable_id', $record->user_id)->exists()) {
                return null;
            }
        }

        return $record;
    }
}
