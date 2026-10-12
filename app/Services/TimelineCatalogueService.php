<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\FeedItem;
use App\Models\Modules\Forum\Poll;
use App\Models\Song;
use App\Models\User;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Support\ContributionsModule;
use App\Modules\Podcast\Models\PodcastEpisode;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Store\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TimelineCatalogueService
{
    private const SOURCES = ['song' => 'music', 'episode' => 'podcasts', 'event' => 'events', 'poll' => 'forum', 'product' => 'store', 'service' => 'promotions', 'brief' => 'promotions', 'corpus' => 'ateso'];

    /** Scan eligible source rows in chunks; retain only small cursor/ranking references. */
    public function references(array $modules, Carbon $at, ?User $viewer): Collection
    {
        $references = collect();
        foreach (self::SOURCES as $kind => $module) {
            if (! in_array($module, $modules, true)) {
                continue;
            }
            $this->query($kind, $at, $viewer)->chunkById(500, function (Collection $models) use (&$references, $kind, $module) {
                foreach ($models as $model) {
                    if ($kind === 'corpus' && ! $this->safePair($model)) {
                        continue;
                    }
                    $date = $model->published_at ?? $model->created_at;
                    $actor = match ($kind) {
                        'song' => $model->artist?->user_id,
                        'episode' => $model->podcast?->artist?->user_id,
                        'event' => $model->user_id ?? $model->organizer_id,
                        'poll' => $model->is_anonymous ? null : $model->user_id,
                        'product', 'service' => $model->store?->user_id,
                        'brief' => $model->created_by_user_id,
                        default => null,
                    };
                    $actionable = match ($kind) {
                        'event' => ($model->ends_at ?? $model->starts_at)?->isFuture() ?? false,
                        'poll' => $model->isActive(),
                        'brief' => $model->status === 'open' && (! $model->deadline_at || $model->deadline_at->isFuture()),
                        'service', 'product' => true,
                        default => false,
                    };
                    $identity = ($kind === 'service' ? 'product' : $kind).':'.$model->id;
                    $references->push(['key' => $identity, 'identity' => $identity, 'native' => $kind, 'id' => $model->id, 'module' => $module, 'actor' => $actor, 'at' => $date, 'actionable' => $actionable, 'engagement' => (int) ($model->total_responses ?? $model->play_count ?? $model->view_count ?? 0)]);
                }
            });
        }
        if (in_array('ateso', $modules, true) && ($project = $this->project($at))) {
            $references->push(['key' => 'project:ateso', 'identity' => 'project:ateso', 'native' => 'project', 'id' => 0, 'module' => 'ateso', 'actor' => null, 'at' => $project->published_at, 'actionable' => true, 'engagement' => 0]);
        }

        return $references;
    }

    /** Hydrate only the requested cursor page, rechecking publication and access. */
    public function hydrate(Collection $references, Carbon $at, ?User $viewer): Collection
    {
        $items = collect();
        foreach ($references->groupBy('native') as $kind => $group) {
            if ($kind === 'project') {
                if ($project = $this->project($at)) {
                    $items->put('project:ateso', $project);
                }

                continue;
            }
            $models = $this->query($kind, $at, $viewer)->whereKey($group->pluck('id'))->get();
            foreach ($models as $model) {
                if ($kind === 'corpus' && ! $this->safePair($model)) {
                    continue;
                }
                $item = $this->toItem($kind, $model);
                $items->put(($kind === 'service' ? 'product' : $kind).':'.$model->id, $item);
            }
        }

        return $items;
    }

    public function available(string $kind, int $id, ?User $viewer): ?Model
    {
        if ($kind === 'product') {
            return $this->query('product', now(), $viewer)->find($id) ?? $this->query('service', now(), $viewer)->find($id);
        }
        if (! array_key_exists($kind, self::SOURCES)) {
            return null;
        }
        $model = $this->query($kind, now(), $viewer)->find($id);

        return $kind === 'corpus' && $model && ! $this->safePair($model) ? null : $model;
    }

    private function query(string $kind, Carbon $at, ?User $viewer): Builder
    {
        $query = match ($kind) {
            'song' => Song::query()->published()->where('visibility', 'public')->whereHas('artist', fn ($q) => $q->whereIn('status', Artist::VISIBLE_STATUSES))->with(['artist.user', 'primaryGenre']),
            'episode' => PodcastEpisode::query()->where('status', 'published')->whereNotNull('published_at')->whereHas('podcast', fn ($q) => $q->where('status', 'published'))->with('podcast.artist.user'),
            'event' => Event::query()->where('status', 'published')->where('visibility', 'public')->where('hide_from_non_qualifying', false)->with(['user', 'artist']),
            'poll' => Poll::query()->published()->whereIn('audience', $viewer ? ['all', 'users'] : ['all'])->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))->with('user'),
            'product' => Product::query()->active()->where(fn ($q) => $q->whereNull('product_type')->orWhere('product_type', '!=', Product::TYPE_PROMOTION))->whereHas('store', fn ($q) => $q->where('status', 'active'))->with('store.user'),
            'service' => Product::query()->promotion()->active()->whereHas('store', fn ($q) => $q->whereNotIn('status', ['suspended', 'rejected', 'closed', 'inactive']))->with('store.user'),
            'brief' => PromotionRequest::query()->whereIn('status', ['open', 'reviewing', 'awarded', 'closed'])->with('creator'),
            'corpus' => CorpusPair::query()->where('license_version', 'CC-BY-SA-4.0')->whereHas('submission', fn ($q) => $q->where('status', 'accepted')->whereIn('user_id', ContributorProfile::query()->whereNotNull('consented_at')->select('user_id'))->whereHas('task', fn ($task) => $task->where('is_gold', false))),
        };
        if ($kind === 'corpus' && ! ContributionsModule::feedCardsEnabled()) {
            $query->whereRaw('1 = 0');
        }
        if (in_array($kind, ['song', 'episode', 'event', 'product', 'service'], true)) {
            $query->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', $at));
        }

        return $query->where('created_at', '<=', $at);
    }

    private function safePair(CorpusPair $pair): bool
    {
        $text = $pair->en_text.' '.$pair->ateso_text;

        return trim((string) $pair->en_text) !== '' && trim((string) $pair->ateso_text) !== ''
            && ! preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|\+?\d[\d\s()-]{7,}\d/i', $text);
    }

    private function toItem(string $kind, Model $model): FeedItem
    {
        $date = $model->published_at ?? $model->created_at;
        $actor = match ($kind) {
            'song' => $model->artist?->user,
            'episode' => $model->podcast?->artist?->user,
            'event' => $model->user,
            'poll' => $model->is_anonymous ? null : $model->user,
            'product', 'service' => $model->store?->user,
            'brief' => $model->creator,
            default => null,
        };
        $item = new FeedItem([
            'uuid' => 'catalog-'.$kind.'-'.$model->id, 'type' => match ($kind) {
                'song' => 'song_release', 'episode' => 'episode_published', 'event' => 'event_created', 'poll' => 'poll_created', 'brief' => 'promotion_request_posted', 'corpus' => 'corpus_pair_accepted', 'service' => 'promotion_service_listed', default => 'product_listed',
            }, 'module' => self::SOURCES[$kind], 'title' => $model->title ?? $model->name ?? 'Ateso from the community',
            'actor_name' => $kind === 'song' ? $model->artist?->stage_name : ($actor?->name ?? ($kind === 'corpus' ? 'Ateso community' : 'TesoTunes')),
            'actor_id' => $actor?->id, 'actor_type' => $actor ? 'user' : 'community',
            'actor_avatar_url' => $actor?->avatar_url, 'actor_verified' => (bool) $actor?->is_verified,
            'published_at' => $date, 'visibility' => 'public', 'subject_type' => get_class($model), 'subject_id' => $model->id,
            'extras' => ['catalog_discovery' => true, 'date_label' => $kind === 'corpus' ? 'Accepted' : ($model->published_at ? 'Published' : 'Added to catalogue')],
        ]);
        $item->id = -$model->id;
        $item->setRelation('subject', $model);

        return $item;
    }

    public function project(Carbon $at): ?FeedItem
    {
        if (! ContributionsModule::feedCardsEnabled()) {
            return null;
        }
        $tasks = ContributionTask::query()->where('type', 'translate')->where('is_gold', false)->where('created_at', '<=', $at);
        $corpus = $this->query('corpus', $at, null);
        $accepted = (clone $corpus)->count();
        $open = (clone $tasks)->where('status', 'open')->count();
        $last = collect([(clone $tasks)->max('created_at'), (clone $corpus)->max('created_at')])->filter()->sortDesc()->first();
        if (! $last || (! $accepted && ! $open)) {
            return null;
        }

        $project = new FeedItem(['uuid' => 'project-ateso', 'type' => 'ateso_project', 'module' => 'ateso', 'title' => 'Give Ateso more words to learn from', 'actor_name' => 'Ateso community', 'actor_type' => 'community', 'visibility' => 'public', 'published_at' => Carbon::parse($last), 'extras' => ['catalog_discovery' => true, 'date_label' => 'Project activity', 'accepted_pairs' => $accepted, 'open_tasks' => $open], 'actions' => [['url' => '/contribute', 'label' => 'Try a translation', 'type' => 'view']]]);
        $project->id = -9000000;

        return $project;
    }
}
