<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\SongResource;
use App\Models\Artist;
use App\Models\Event;
use App\Notifications\NewFollowerNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
    /**
     * GET /api/artists
     * Paginated list of active artists.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $artists = Artist::with('primaryGenre')
            ->where('status', 'active')
            ->when($request->filled('verified_only'), fn ($q) => $q->where('is_verified', $request->boolean('verified_only')))
            ->when($request->filled('country'), fn ($q) => $q->where('country', $request->country))
            ->when($request->filled('genre'), fn ($q) => $q->where('primary_genre_id', $request->genre))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('stage_name', 'like', '%'.escape_like($request->search).'%');
            })
            ->orderByDesc('followers_count')
            ->paginate($perPage);

        return ArtistResource::collection($artists);
    }

    /**
     * GET /api/artists/{artist}
     * Single artist by ID, slug, or UUID.
     */
    public function show(string $artist)
    {
        $record = Artist::with(['primaryGenre'])
            ->withCount(['songs' => function ($q) {
                $q->where('status', 'published');
            }, 'albums' => function ($q) {
                $q->where('status', 'published');
            }])
            ->where('status', 'active')
            ->where(function ($q) use ($artist) {
                $q->where('id', $artist)
                    ->orWhere('slug', $artist)
                    ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        // Use accurate published counts
        $record->total_songs_count = $record->songs_count;
        $record->total_albums_count = $record->albums_count;

        return new ArtistResource($record);
    }

    /**
     * GET /api/artists/{artist}/songs
     * Paginated songs for an artist.
     */
    public function songs(string $artist, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $record = Artist::where('status', 'active')
            ->where(function ($q) use ($artist) {
                $q->where('id', $artist)
                    ->orWhere('slug', $artist)
                    ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        $songs = $record->songs()
            ->with(['artist', 'album', 'primaryGenre'])
            ->where('status', 'published')
            ->orderByDesc('play_count')
            ->paginate($perPage);

        return SongResource::collection($songs);
    }

    /**
     * GET /api/artists/{artist}/albums
     * Paginated albums for an artist.
     */
    public function albums(string $artist, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);

        $record = Artist::where('status', 'active')
            ->where(function ($q) use ($artist) {
                $q->where('id', $artist)
                    ->orWhere('slug', $artist)
                    ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        $albums = $record->albums()
            ->with(['artist', 'primaryGenre'])
            ->where('status', 'published')
            ->orderByDesc('release_date')
            ->paginate($perPage);

        return AlbumResource::collection($albums);
    }

    /**
     * GET /api/artists/{artist}/events
     * Public event listing for an artist profile.
     */
    public function events(string $artist): JsonResponse
    {
        $record = Artist::with('user')
            ->where('status', 'active')
            ->where(function ($query) use ($artist) {
                $query->where('id', $artist)
                    ->orWhere('slug', $artist)
                    ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        $viewer = auth('sanctum')->user() ?? auth()->user();

        $events = Event::with(['tickets', 'interestedUsers'])
            ->where('status', 'published')
            ->where(function ($query) use ($record) {
                $query->where('artist_id', $record->id);

                if ($record->user_id) {
                    $query->orWhere('organizer_id', $record->user_id)
                        ->orWhere('user_id', $record->user_id);
                }
            })
            ->orderByDesc('starts_at')
            ->get();

        $formatted = $events->map(function (Event $event) use ($viewer) {
            $startsAt = $event->starts_at;
            $endsAt = $event->ends_at;
            $status = 'upcoming';

            if ($event->status === 'cancelled') {
                $status = 'cancelled';
            } elseif ($endsAt && $endsAt->isPast()) {
                $status = 'past';
            } elseif ($startsAt && $startsAt->isPast() && (! $endsAt || $endsAt->isFuture())) {
                $status = 'live';
            }

            $prices = $event->tickets
                ->where('is_active', true)
                ->pluck('price_ugx')
                ->filter(fn ($price) => $price !== null);

            $attending = $viewer
                ? $event->attendees()
                    ->where('user_id', $viewer->id)
                    ->whereIn('status', ['pending', 'confirmed', 'attended'])
                    ->exists()
                : false;

            $interested = $viewer
                ? $event->interestedUsers->contains('id', $viewer->id)
                : false;

            return [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
                'type' => $event->event_type ?: ($event->category ?: 'concert'),
                'description' => $event->description ?? '',
                'cover_url' => $event->artwork ? \App\Helpers\StorageHelper::url($event->artwork) : null,
                'date' => $startsAt?->toIso8601String(),
                'end_date' => $endsAt?->toIso8601String(),
                'venue' => [
                    'name' => $event->venue_name ?? 'TBA',
                    'address' => $event->venue_address ?? '',
                    'city' => $event->city ?? '',
                    'country' => $event->country ?? 'Uganda',
                ],
                'is_virtual' => (bool) $event->is_virtual,
                'tickets_url' => url("/events/{$event->id}"),
                'ticket_price_min' => $prices->isNotEmpty() ? (float) $prices->min() : null,
                'ticket_price_max' => $prices->isNotEmpty() ? (float) $prices->max() : null,
                'attendees_count' => (int) ($event->attendee_count ?? $event->confirmed_attendees_count),
                'interested_count' => (int) $event->interestedUsers->count(),
                'is_attending' => $attending,
                'is_interested' => $interested,
                'status' => $status,
            ];
        })->values();

        return response()->json([
            'artist' => [
                'id' => $record->id,
                'name' => $record->stage_name,
                'slug' => $record->slug,
                'avatar_url' => $record->avatar_url,
            ],
            'upcoming' => $formatted->filter(fn ($event) => in_array($event['status'], ['upcoming', 'live'], true))->values(),
            'past' => $formatted->filter(fn ($event) => in_array($event['status'], ['past', 'cancelled'], true))->values(),
        ]);
    }

    public function toggleFollow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            $isFollowing = $user->following()
                ->where('following_id', $artist->id)
                ->where('following_type', 'artist')
                ->first();

            if ($isFollowing) {
                $isFollowing->delete();
                $artist->decrement('follower_count');
                $message = 'Artist unfollowed';
                $following = false;
            } else {
                $user->following()->create([
                    'following_id' => $artist->id,
                    'type' => 'artist',
                ]);
                $artist->increment('follower_count');
                $message = 'Artist followed';
                $following = true;

                // Notify the artist about their new follower
                if ($artist->user && $artist->user->id !== $user->id) {
                    $artist->user->notify(new NewFollowerNotification($user));
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'is_following' => $following,
                'follower_count' => $artist->fresh()->follower_count,
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist toggle follow error: '.$e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle follow',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Follow an artist
     */
    public function follow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            $isAlreadyFollowing = $user->following()
                ->where('following_id', $artist->id)
                ->where('following_type', 'artist')
                ->exists();

            if ($isAlreadyFollowing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already following this artist',
                ], 400);
            }

            $user->following()->create([
                'following_id' => $artist->id,
                'type' => 'artist',
            ]);

            $artist->increment('follower_count');

            return response()->json([
                'success' => true,
                'message' => 'Artist followed successfully',
                'is_following' => true,
                'follower_count' => $artist->fresh()->follower_count,
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist follow error: '.$e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to follow artist',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Unfollow an artist
     */
    public function unfollow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            $deleted = $user->following()
                ->where('following_id', $artist->id)
                ->where('following_type', 'artist')
                ->delete();

            if ($deleted) {
                $artist->decrement('follower_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Artist unfollowed successfully',
                'is_following' => false,
                'follower_count' => $artist->fresh()->follower_count,
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist unfollow error: '.$e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unfollow artist',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
