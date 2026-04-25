<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollVote;
use App\Models\Song;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PollsApiController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/admin/polls/stats
     */
    public function stats()
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:polls:stats', now()->addMinutes(5), function () {
                return [
                    'total_polls' => Poll::count(),
                    'active_polls' => Poll::where('status', 'active')->count(),
                    'closed_polls' => Poll::where('status', 'closed')->count(),
                    'song_battles' => Poll::where('poll_type', Poll::TYPE_SONG_BATTLE)->count(),
                    'artist_contests' => Poll::where('poll_type', Poll::TYPE_ARTIST_CONTEST)->count(),
                    'total_votes' => PollVote::count(),
                    'recent_polls_30d' => Poll::where('created_at', '>=', now()->subDays(30))->count(),
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        }, 'Failed to retrieve poll stats.');
    }

    /**
     * GET /api/admin/polls
     */
    public function index(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $polls = Poll::with(['user', 'options.song.artist', 'options.artist'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $q->where('title', 'like', '%'.addcslashes($request->search, '%_').'%');
                })
                ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('poll_type'), fn ($q) => $q->where('poll_type', $request->poll_type))
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $polls->items(),
                'meta' => [
                    'current_page' => $polls->currentPage(),
                    'last_page' => $polls->lastPage(),
                    'per_page' => $polls->perPage(),
                    'total' => $polls->total(),
                ],
            ]);
        }, 'Failed to retrieve polls.');
    }

    /**
     * GET /api/admin/polls/{id}
     */
    public function show(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            $poll = Poll::with(['user', 'options.song.artist', 'options.artist', 'options.votes'])->findOrFail($id);

            return response()->json(['success' => true, 'data' => $poll]);
        }, 'Failed to retrieve poll.');
    }

    /**
     * POST /api/admin/polls
     * Supports three poll types:
     *   general       — free-text options (original behaviour)
     *   song_battle   — each option references a Song by song_id
     *   artist_contest— each option references an Artist by artist_id
     */
    public function store(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'poll_type' => 'in:general,song_battle,artist_contest',
                'category' => 'nullable|string|max:100',
                'credits_reward' => 'integer|min:1|max:20',
                'allow_multiple_votes' => 'boolean',
                'show_results_before_vote' => 'boolean',
                'is_anonymous' => 'boolean',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after:starts_at',
                'status' => 'in:active,draft,closed',

                // General poll options
                'options' => 'required_if:poll_type,general|array|min:2|max:10',
                'options.*' => 'required_if:poll_type,general|string|max:255',

                // Song battle options
                'song_options' => 'required_if:poll_type,song_battle|array|min:2|max:10',
                'song_options.*.song_id' => 'required_if:poll_type,song_battle|exists:songs,id',
                'song_options.*.label' => 'nullable|string|max:255',

                // Artist contest options
                'artist_options' => 'required_if:poll_type,artist_contest|array|min:2|max:10',
                'artist_options.*.artist_id' => 'required_if:poll_type,artist_contest|exists:artists,id',
                'artist_options.*.label' => 'nullable|string|max:255',
            ]);

            return DB::transaction(function () use ($validated, $request) {
                $pollType = $validated['poll_type'] ?? Poll::TYPE_GENERAL;

                $poll = Poll::create([
                    'user_id' => $request->user()->id,
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'poll_type' => $pollType,
                    'category' => $validated['category'] ?? null,
                    'credits_reward' => $validated['credits_reward'] ?? 3,
                    'allow_multiple_votes' => $validated['allow_multiple_votes'] ?? false,
                    'show_results_before_vote' => $validated['show_results_before_vote'] ?? true,
                    'is_anonymous' => $validated['is_anonymous'] ?? false,
                    'starts_at' => $validated['starts_at'] ?? now(),
                    'ends_at' => $validated['ends_at'] ?? null,
                    'status' => $validated['status'] ?? 'active',
                ]);

                match ($pollType) {
                    Poll::TYPE_SONG_BATTLE => $this->createSongOptions($poll, $validated['song_options']),
                    Poll::TYPE_ARTIST_CONTEST => $this->createArtistOptions($poll, $validated['artist_options']),
                    default => $this->createTextOptions($poll, $validated['options']),
                };

                Cache::forget('admin:polls:stats');

                return response()->json([
                    'success' => true,
                    'message' => 'Poll created successfully',
                    'data' => $poll->load(['options.song.artist', 'options.artist']),
                ], 201);
            });
        }, 'Failed to create poll.');
    }

    /**
     * PUT /api/admin/polls/{id}
     */
    public function update(Request $request, int $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $poll = Poll::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string|max:1000',
                'category' => 'nullable|string|max:100',
                'credits_reward' => 'integer|min:1|max:20',
                'allow_multiple_votes' => 'boolean',
                'show_results_before_vote' => 'boolean',
                'is_anonymous' => 'boolean',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date',
                'status' => 'in:active,draft,closed',
                'options' => 'sometimes|array|min:2|max:10',
                'options.*' => 'required|string|max:255',
            ]);

            $poll->update(collect($validated)->except('options')->toArray());

            if (isset($validated['options'])) {
                if ($poll->total_votes > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot change options after votes have been cast',
                    ], 422);
                }

                $poll->options()->delete();
                $this->createTextOptions($poll, $validated['options']);
            }

            Cache::forget('admin:polls:stats');

            return response()->json([
                'success' => true,
                'message' => 'Poll updated successfully',
                'data' => $poll->fresh(['options.song.artist', 'options.artist']),
            ]);
        }, 'Failed to update poll.');
    }

    /**
     * DELETE /api/admin/polls/{id}
     */
    public function destroy(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            Poll::findOrFail($id)->delete();
            Cache::forget('admin:polls:stats');

            return response()->json(['success' => true, 'message' => 'Poll deleted successfully']);
        }, 'Failed to delete poll.');
    }

    /**
     * POST /api/admin/polls/{id}/close
     */
    public function close(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            Poll::findOrFail($id)->close();

            return response()->json(['success' => true, 'message' => 'Poll closed successfully']);
        }, 'Failed to close poll.');
    }

    /**
     * POST /api/admin/polls/{id}/reopen
     */
    public function reopen(int $id)
    {
        return $this->handleApiAction(function () use ($id) {
            Poll::findOrFail($id)->update(['status' => 'active']);

            return response()->json(['success' => true, 'message' => 'Poll reopened successfully']);
        }, 'Failed to reopen poll.');
    }

    // ── Private helpers ────────────────────────────────────────

    private function createTextOptions(Poll $poll, array $options): void
    {
        foreach ($options as $index => $text) {
            PollOption::create([
                'poll_id' => $poll->id,
                'option_text' => $text,
                'position' => $index,
            ]);
        }
    }

    private function createSongOptions(Poll $poll, array $options): void
    {
        $songIds = collect($options)->pluck('song_id');
        $songs = Song::whereIn('id', $songIds)
            ->with('artist:id,stage_name')
            ->get()
            ->keyBy('id');

        foreach ($options as $index => $item) {
            $song = $songs->get($item['song_id']);
            $label = $item['label'] ?? ($song ? "{$song->title} – {$song->artist?->stage_name}" : "Song {$index}");

            PollOption::create([
                'poll_id' => $poll->id,
                'song_id' => $item['song_id'],
                'option_text' => $label,
                'position' => $index,
            ]);
        }
    }

    private function createArtistOptions(Poll $poll, array $options): void
    {
        $artistIds = collect($options)->pluck('artist_id');
        $artists = Artist::whereIn('id', $artistIds)->get()->keyBy('id');

        foreach ($options as $index => $item) {
            $artist = $artists->get($item['artist_id']);
            $label = $item['label'] ?? ($artist?->stage_name ?? "Artist {$index}");

            PollOption::create([
                'poll_id' => $poll->id,
                'artist_id' => $item['artist_id'],
                'option_text' => $label,
                'position' => $index,
            ]);
        }
    }
}
