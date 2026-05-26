<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\SongResource;
use App\Models\Artist;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\Song;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FeaturedContentController extends Controller
{
    /**
     * GET /api/featured
     * Homepage-ready editorial items with graceful fallbacks.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->get('limit', 5), 1), 10);

        $editorialItems = $this->loadEditorialItems($request, $limit);
        $songs = $this->loadSongs($limit);
        $events = $this->loadEvents($limit);

        $fallbackItems = $this->interleave(
            $songs->map(fn (Song $song) => $this->transformSong($request, $song)),
            $events->map(fn (Event $event) => $this->transformEvent($event))
        );

        $items = $editorialItems
            ->concat($fallbackItems)
            ->unique(fn (array $item) => ($item['type'] ?? 'item').'|'.($item['link'] ?? $item['id']))
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $items,
        ]);
    }

    private function loadEditorialItems(Request $request, int $limit): Collection
    {
        if (! Schema::hasTable('featured_content')) {
            return collect();
        }

        $items = FeaturedContent::query()
            ->active()
            ->live()
            ->with(['song.artist', 'song.album', 'album.artist', 'artist', 'event.organizer', 'event.location', 'playlist.owner'])
            ->orderBy('sort_order')
            ->take($limit)
            ->get();

        return $items->map(fn (FeaturedContent $item) => $this->transformEditorialItem($request, $item));
    }

    private function loadSongs(int $limit): Collection
    {
        $baseQuery = Song::with(['artist', 'album', 'primaryGenre'])
            ->published()
            ->whereHas('artist', fn ($query) => $query->whereIn('status', Artist::VISIBLE_STATUSES));

        $featuredSongs = (clone $baseQuery)
            ->where('is_featured', true)
            ->orderByDesc('play_count')
            ->take($limit)
            ->get();

        if ($featuredSongs->isNotEmpty()) {
            return $featuredSongs;
        }

        return (clone $baseQuery)
            ->orderByDesc('play_count')
            ->take($limit)
            ->get();
    }

    private function loadEvents(int $limit): Collection
    {
        $baseQuery = Event::with(['organizer', 'location'])
            ->published()
            ->upcoming();

        $featuredEvents = (clone $baseQuery)
            ->featured()
            ->orderBy('starts_at')
            ->take($limit)
            ->get();

        if ($featuredEvents->isNotEmpty()) {
            return $featuredEvents;
        }

        return (clone $baseQuery)
            ->orderBy('starts_at')
            ->take($limit)
            ->get();
    }

    private function transformSong(Request $request, Song $song): array
    {
        $resource = (new SongResource($song))->toArray($request);
        $artistName = data_get($resource, 'artist.name') ?? $song->artist?->stage_name ?? 'TesoTunes';
        $imageUrl = $resource['artwork_url']
            ?? data_get($resource, 'album.artwork_url')
            ?? ($song->artist ? StorageHelper::avatarUrl($song->artist->avatar, $artistName) : null);

        return [
            'id' => $song->id,
            'title' => $song->title,
            'subtitle' => trim($artistName.' · '.number_format((int) ($song->play_count ?? 0)).' plays'),
            'image_url' => $imageUrl,
            'link' => '/songs/'.$song->slug,
            'type' => 'song',
            'song' => $resource,
        ];
    }

    private function transformEvent(Event $event): array
    {
        $city = $event->city ?: $event->location?->city;
        $date = $event->starts_at?->format('M j');
        $parts = array_values(array_filter([$city, $date]));
        $organizerName = $event->organizer?->name ?? $event->title;
        $imageUrl = StorageHelper::artworkUrl($event->artwork)
            ?? StorageHelper::artworkUrl($event->banner)
            ?? ($event->organizer ? StorageHelper::avatarUrl($event->organizer->avatar, $organizerName) : null);

        return [
            'id' => $event->id,
            'title' => $event->title,
            'subtitle' => implode(' · ', $parts) ?: 'Upcoming event',
            'image_url' => $imageUrl,
            'link' => '/events/'.$event->id,
            'type' => 'event',
        ];
    }

    private function transformEditorialItem(Request $request, FeaturedContent $item): array
    {
        $songResource = null;
        $albumResource = null;
        $artistResource = null;
        $eventResource = null;
        $playlistResource = null;
        $derivedImage = null;
        $derivedLink = $item->link;
        $derivedTitle = $item->title;
        $derivedSubtitle = $item->subtitle;

        if ($item->song) {
            $songResource = (new SongResource($item->song))->toArray($request);
            $derivedImage = $songResource['artwork_url']
                ?? data_get($songResource, 'album.artwork_url')
                ?? data_get($songResource, 'artist.avatar_url');
            $derivedLink = '/songs/'.$item->song->slug;
            $derivedTitle = $item->title ?: $item->song->title;
            $artistName = data_get($songResource, 'artist.name');
            $derivedSubtitle = $item->subtitle ?: trim(($artistName ?: 'TesoTunes').' · '.number_format((int) ($item->song->play_count ?? 0)).' plays');
        }

        if ($item->album) {
            $albumResource = (new AlbumResource($item->album))->toArray($request);
            $derivedImage = $derivedImage
                ?? ($albumResource['artwork_url'] ?? null)
                ?? data_get($albumResource, 'artist.avatar_url');
            $derivedLink = '/albums/'.$item->album->slug;
            $derivedTitle = $item->title ?: $item->album->title;
            $albumArtistName = data_get($albumResource, 'artist.name');
            $derivedSubtitle = $item->subtitle ?: ($albumArtistName ?: 'Album spotlight');
        }

        if ($item->artist) {
            $artistResource = (new ArtistResource($item->artist))->toArray($request);
            $derivedImage = $derivedImage
                ?? ($artistResource['avatar_url'] ?? null)
                ?? ($artistResource['banner_url'] ?? null);
            $derivedLink = '/artists/'.$item->artist->slug;
            $derivedTitle = $item->title ?: ($artistResource['name'] ?? $item->artist->stage_name);
            $derivedSubtitle = $item->subtitle ?: ($artistResource['bio'] ?? 'Featured artist');
        }

        if ($item->event) {
            $eventResource = (new EventResource($item->event))->toArray($request);
            $derivedImage = $derivedImage
                ?? ($eventResource['artwork'] ?? null)
                ?? ($eventResource['banner'] ?? null)
                ?? data_get($eventResource, 'organizer.avatar');
            $derivedLink = '/events/'.$item->event->id;
            $derivedTitle = $item->title ?: $item->event->title;
            $eventSummary = collect([
                $item->event->city ?: $item->event->location?->city,
                $item->event->starts_at?->format('M j'),
            ])->filter()->implode(' · ');
            $derivedSubtitle = $item->subtitle ?: ($eventSummary ?: 'Upcoming event');
        }

        if ($item->playlist) {
            $playlistResource = (new PlaylistResource($item->playlist))->toArray($request);
            $derivedImage = $derivedImage ?? ($playlistResource['artwork_url'] ?? null);
            $derivedLink = '/playlists/'.$item->playlist->slug;
            $derivedTitle = $item->title ?: $item->playlist->name;
            $derivedSubtitle = $item->subtitle ?: ($item->playlist->description ?: 'Featured playlist');
        }

        if (! $derivedImage && $item->artist) {
            $derivedImage = StorageHelper::avatarUrl($item->artist->avatar, $item->artist->stage_name ?? $item->artist->name ?? 'Artist');
        }

        return [
            'id' => $item->id,
            'title' => $derivedTitle,
            'subtitle' => $derivedSubtitle,
            'image_url' => StorageHelper::artworkUrl($item->image_path) ?? $derivedImage,
            'link' => $derivedLink,
            'type' => $item->type,
            'song' => $songResource,
        ];
    }

    private function interleave(Collection $primary, Collection $secondary): Collection
    {
        $items = collect();
        $max = max($primary->count(), $secondary->count());

        for ($index = 0; $index < $max; $index++) {
            if ($primary->has($index)) {
                $items->push($primary->get($index));
            }

            if ($secondary->has($index)) {
                $items->push($secondary->get($index));
            }
        }

        return $items;
    }
}
