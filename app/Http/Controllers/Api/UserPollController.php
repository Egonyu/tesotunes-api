<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PollResource;
use App\Models\Artist;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use App\Models\Song;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserPollController extends Controller
{
    use HandlesApiErrors;

    private const MAX_POLLS_PER_DAY = 3;

    /**
     * POST /api/polls
     * Users can create general polls, song battles, and artist contests.
     */
    public function store(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $user = $request->user();

            // Soft daily cap — prevents spam, encourages quality
            $todayCount = Poll::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            if ($todayCount >= self::MAX_POLLS_PER_DAY) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can create up to '.self::MAX_POLLS_PER_DAY.' polls per day.',
                ], 429);
            }

            $pollType = $request->input('poll_type', Poll::TYPE_GENERAL);

            $validated = $request->validate([
                'title'                    => 'required|string|max:255',
                'description'              => 'nullable|string|max:1000',
                'poll_type'                => 'in:general,song_battle,artist_contest',
                'category'                 => 'nullable|string|max:100',
                'credits_reward'           => 'integer|min:1|max:10',
                'allow_multiple_votes'     => 'boolean',
                'show_results_before_vote' => 'boolean',
                'ends_at'                  => 'nullable|date|after:now',

                // General
                'options'                  => 'required_if:poll_type,general|array|min:2|max:10',
                'options.*'                => 'required_if:poll_type,general|string|max:255',

                // Song battle
                'song_options'             => 'required_if:poll_type,song_battle|array|min:2|max:8',
                'song_options.*.song_id'   => 'required_if:poll_type,song_battle|exists:songs,id',

                // Artist contest
                'artist_options'              => 'required_if:poll_type,artist_contest|array|min:2|max:8',
                'artist_options.*.artist_id'  => 'required_if:poll_type,artist_contest|exists:artists,id',
            ]);

            return DB::transaction(function () use ($validated, $user, $pollType) {
                $poll = Poll::create([
                    'user_id'                  => $user->id,
                    'title'                    => $validated['title'],
                    'description'              => $validated['description'] ?? null,
                    'poll_type'                => $pollType,
                    'category'                 => $validated['category'] ?? null,
                    'credits_reward'           => min((int) ($validated['credits_reward'] ?? 3), 10),
                    'allow_multiple_votes'     => $validated['allow_multiple_votes'] ?? false,
                    'show_results_before_vote' => $validated['show_results_before_vote'] ?? true,
                    'is_anonymous'             => false,
                    'starts_at'                => now(),
                    'ends_at'                  => $validated['ends_at'] ?? now()->addDays(7),
                    'status'                   => 'active',
                ]);

                match ($pollType) {
                    Poll::TYPE_SONG_BATTLE    => $this->createSongOptions($poll, $validated['song_options']),
                    Poll::TYPE_ARTIST_CONTEST => $this->createArtistOptions($poll, $validated['artist_options']),
                    default                   => $this->createTextOptions($poll, $validated['options']),
                };

                return response()->json([
                    'success' => true,
                    'message' => 'Poll created successfully',
                    'data'    => new PollResource($poll->load(['options.song.artist', 'options.artist', 'user'])),
                ], 201);
            });
        }, 'Failed to create poll.');
    }

    private function createTextOptions(Poll $poll, array $options): void
    {
        foreach ($options as $index => $text) {
            PollOption::create(['poll_id' => $poll->id, 'option_text' => $text, 'position' => $index]);
        }
    }

    private function createSongOptions(Poll $poll, array $options): void
    {
        $songs = Song::whereIn('id', collect($options)->pluck('song_id'))
            ->with('artist:id,stage_name')
            ->get()->keyBy('id');

        foreach ($options as $index => $item) {
            $song = $songs->get($item['song_id']);
            PollOption::create([
                'poll_id'     => $poll->id,
                'song_id'     => $item['song_id'],
                'option_text' => $song ? "{$song->title} – {$song->artist?->stage_name}" : "Track {$index}",
                'position'    => $index,
            ]);
        }
    }

    private function createArtistOptions(Poll $poll, array $options): void
    {
        $artists = Artist::whereIn('id', collect($options)->pluck('artist_id'))->get()->keyBy('id');

        foreach ($options as $index => $item) {
            $artist = $artists->get($item['artist_id']);
            PollOption::create([
                'poll_id'     => $poll->id,
                'artist_id'   => $item['artist_id'],
                'option_text' => $artist?->stage_name ?? "Artist {$index}",
                'position'    => $index,
            ]);
        }
    }
}
