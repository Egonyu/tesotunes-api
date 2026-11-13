<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Modules\Forum\Poll;
use App\Models\Song;
use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Support\ContributionsModule;
use App\Modules\Store\Models\Deal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Today in Teso": the one card a person checks in the morning.
 *
 * Everything on it is a live record that is already public elsewhere on the
 * platform (the same visibility rules as the timeline), ordered so the
 * viewer's own town comes first. Nothing is padded: a quiet day returns
 * empty sections, and the client shows the card smaller.
 */
class TodayCardService
{
    public function __construct(private CreditService $credits) {}

    public function build(?User $viewer, ?Carbon $at = null): array
    {
        $at ??= now();
        $town = trim((string) $viewer?->city) ?: null;

        return [
            'date' => $at->toDateString(),
            'town' => $town,
            'tonight' => $this->tonight($at, $town),
            'releases' => $this->releases($at),
            'poll' => $this->poll($at, $viewer),
            'ateso' => $this->ateso($at),
            'deal' => $this->deal($at, $town),
            'streak' => $viewer ? $this->streak($viewer) : null,
        ];
    }

    /** Public events starting within the next day, home town first. */
    private function tonight(Carbon $at, ?string $town): array
    {
        return Event::query()->where('status', 'published')->where('visibility', 'public')->where('hide_from_non_qualifying', false)
            ->whereBetween('starts_at', [$at, $at->copy()->addDay()])
            ->when($town, fn (Builder $q) => $q->orderByRaw('CASE WHEN LOWER(city) = ? THEN 0 ELSE 1 END', [mb_strtolower($town)]))
            ->orderBy('starts_at')->limit(3)->get()
            ->map(fn (Event $event) => [
                'kind' => 'event', 'title' => $event->title, 'href' => '/events/'.$event->slug, 'image' => $event->featured_image_url,
                'starts_at' => $event->starts_at?->toIso8601String(), 'location' => implode(' · ', array_filter([$event->venue_name, $event->city])), 'is_free' => (bool) $event->is_free,
            ])->values()->all();
    }

    /** Songs published in the last day by visible artists. */
    private function releases(Carbon $at): array
    {
        return Song::query()->published()->where('visibility', 'public')
            ->whereHas('artist', fn (Builder $q) => $q->whereIn('status', Artist::VISIBLE_STATUSES))
            ->whereBetween('published_at', [$at->copy()->subDay(), $at])
            ->with('artist')->orderByDesc('published_at')->limit(3)->get()
            ->map(fn (Song $song) => [
                'kind' => 'song', 'title' => $song->title, 'href' => '/songs/'.$song->slug, 'image' => $song->artwork_url,
                'song_id' => $song->id, 'artist' => $song->artist?->stage_name, 'published_at' => $song->published_at?->toIso8601String(),
            ])->values()->all();
    }

    /** One open poll the viewer may take part in, the soonest to close. */
    private function poll(Carbon $at, ?User $viewer): ?array
    {
        $poll = Poll::query()->published()->whereIn('audience', $viewer ? ['all', 'users'] : ['all'])
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByRaw('ends_at IS NULL')->orderBy('ends_at')->first();
        if (! $poll || ! $poll->isActive()) {
            return null;
        }

        return ['kind' => 'poll', 'title' => $poll->title, 'href' => '/polls/'.$poll->id, 'poll_id' => $poll->id, 'ends_at' => $poll->ends_at?->toIso8601String()];
    }

    /** One accepted, consented, licence-clean Ateso pair; the choice rotates daily. */
    private function ateso(Carbon $at): ?array
    {
        if (! ContributionsModule::feedCardsEnabled()) {
            return null;
        }
        $query = CorpusPair::query()->where('license_version', 'CC-BY-SA-4.0')
            ->whereHas('submission', fn (Builder $q) => $q->where('status', 'accepted')
                ->whereIn('user_id', ContributorProfile::query()->whereNotNull('consented_at')->select('user_id'))
                ->whereHas('task', fn (Builder $task) => $task->where('is_gold', false)));
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }
        $pair = (clone $query)->orderBy('id')->skip(crc32($at->toDateString()) % $count)->first();
        if (! $pair || trim((string) $pair->en_text) === '' || trim((string) $pair->ateso_text) === ''
            || preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|\+?\d[\d\s()-]{7,}\d/i', $pair->en_text.' '.$pair->ateso_text)) {
            return null;
        }

        return ['kind' => 'corpus', 'ateso_text' => $pair->ateso_text, 'english_text' => $pair->en_text, 'dialect' => $pair->dialect, 'href' => '/contribute'];
    }

    /** One live partner deal, home town first, ending soonest first. */
    private function deal(Carbon $at, ?string $town): ?array
    {
        $deal = Deal::query()->live($at)->with('store')
            ->when($town, fn (Builder $q) => $q->orderByRaw('CASE WHEN LOWER(town) = ? THEN 0 ELSE 1 END', [mb_strtolower($town)]))
            ->orderByRaw('valid_until IS NULL')->orderBy('valid_until')->first();
        if (! $deal) {
            return null;
        }

        return [
            'kind' => 'deal', 'uuid' => $deal->uuid, 'title' => $deal->title, 'discount_label' => $deal->discount_label,
            'store_name' => $deal->store?->name, 'town' => $deal->town, 'credits_price' => $deal->credits_price,
            'valid_until' => $deal->valid_until?->toIso8601String(), 'claims_left' => $deal->claimsLeft(),
            'href' => $deal->store?->slug ? '/store/shops/'.$deal->store->slug : null,
        ];
    }

    /** The signed-in person's streak, read from the credit ledger the bonus already writes. */
    private function streak(User $viewer): array
    {
        $claimedToday = \App\Models\CreditTransaction::query()->where('user_id', $viewer->id)
            ->where('source', 'daily_login')->whereDate('created_at', today())->exists();

        return ['days' => $this->credits->getLoginStreak($viewer), 'claimed_today' => $claimedToday];
    }
}
