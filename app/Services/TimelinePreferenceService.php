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
            'saved' => array_values(array_unique(array_filter(is_array($state['saved'] ?? null) ? $state['saved'] : [], 'is_string'))),
            'hidden' => array_values(array_unique(array_filter(is_array($state['hidden'] ?? null) ? $state['hidden'] : [], 'is_string'))),
        ];
    }

    public function update(User $user, array $input): array
    {
        if (in_array($input['action'] ?? null, ['save', 'hide'], true)) {
            $record = $this->resolve($input['identity'], $user);
            abort_unless($record, 404, 'This content is no longer available.');
            if ($record instanceof FeedItem) {
                $input['identity'] = $this->identity($record);
            }
        }
        $user->getConnection()->transaction(function () use ($user, $input) {
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
                $field = in_array($action, ['save', 'unsave'], true) ? 'saved' : 'hidden';
                $values = is_array($state[$field] ?? null) ? array_filter($state[$field], 'is_string') : [];
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

    private function resolve(string $identity, User $viewer): ?Model
    {
        [$kind, $id] = explode(':', $identity, 2);
        $record = match ($kind) {
            'feed' => FeedItem::query()->published()->where('visibility', 'public')->where('uuid', $id)->with('subject')->first(),
            'song' => Song::query()->with('artist')->published()->where('visibility', 'public')->whereHas('artist', fn ($query) => $query->whereIn('status', Artist::VISIBLE_STATUSES))->find($id),
            'episode' => PodcastEpisode::query()->with('podcast')->where('status', 'published')->where('published_at', '<=', now())->whereHas('podcast', fn ($query) => $query->where('status', 'published'))->find($id),
            'post' => Post::query()->where('published_at', '<=', now())->find($id),
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
