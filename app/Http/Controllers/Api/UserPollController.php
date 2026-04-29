<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePollRequest;
use App\Http\Resources\PollResource;
use App\Models\Artist;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use App\Models\Song;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UserPollController extends Controller
{
    use HandlesApiErrors;

    private const MAX_POLLS_PER_DAY = 3;

    /**
     * POST /api/polls
     *
     * Users may create community polls (general, song_battle, artist_contest).
     * Research surveys are admin-only and routed separately.
     */
    public function store(StorePollRequest $request): Response
    {
        return $this->handleApiAction(function () use ($request) {
            $user = $request->user();

            if ($request->input('poll_type') === Poll::TYPE_RESEARCH_SURVEY) {
                return response()->json(['message' => 'Research surveys can only be created by administrators.'], 403);
            }

            $todayCount = Poll::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            if ($todayCount >= self::MAX_POLLS_PER_DAY) {
                return response()->json([
                    'message' => 'You can create up to '.self::MAX_POLLS_PER_DAY.' polls per day.',
                ], 429);
            }

            $validated = $request->validated();

            return DB::transaction(function () use ($validated, $user) {
                $poll = Poll::create([
                    'user_id' => $user->id,
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'poll_type' => $validated['poll_type'] ?? Poll::TYPE_GENERAL,
                    'category' => $validated['category'] ?? null,
                    'audience' => Poll::AUDIENCE_ALL,
                    'allow_guest_responses' => true,
                    'show_results_before_completion' => $validated['show_results_before_completion'] ?? true,
                    'is_anonymous' => false,
                    'credits_reward' => min((int) ($validated['credits_reward'] ?? 3), 10),
                    'starts_at' => now(),
                    'ends_at' => $validated['ends_at'] ?? now()->addDays(7),
                    'status' => Poll::STATUS_ACTIVE,
                ]);

                $this->createQuestions($poll, $validated['questions']);

                return response()->json([
                    'success' => true,
                    'message' => 'Poll created.',
                    'data' => new PollResource(
                        $poll->load(['questions.options.song.artist', 'questions.options.artist', 'user'])
                    ),
                ], 201);
            });
        }, 'Failed to create poll.');
    }

    /**
     * GET /api/polls/my
     *
     * Lists the authenticated user's polls.
     */
    public function myPolls(Request $request): Response
    {
        return $this->handleApiAction(function () use ($request) {
            $polls = Poll::where('user_id', $request->user()->id)
                ->with(['questions'])
                ->orderByDesc('created_at')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data' => PollResource::collection($polls),
                'meta' => [
                    'current_page' => $polls->currentPage(),
                    'last_page' => $polls->lastPage(),
                    'total' => $polls->total(),
                ],
            ]);
        }, 'Failed to retrieve your polls.');
    }

    // ── Private helpers ────────────────────────────────────────────

    private function createQuestions(Poll $poll, array $questions): void
    {
        foreach ($questions as $position => $questionData) {
            $question = PollQuestion::create([
                'poll_id' => $poll->id,
                'position' => $position,
                'question_text' => $questionData['question_text'],
                'description' => $questionData['description'] ?? null,
                'question_type' => $questionData['question_type'] ?? PollQuestion::TYPE_MULTIPLE_CHOICE,
                'is_required' => $questionData['is_required'] ?? true,
                'allow_multiple' => $questionData['allow_multiple'] ?? false,
                'settings' => $questionData['settings'] ?? null,
            ]);

            if ($question->isChoiceBased() && ! empty($questionData['options'])) {
                $this->createOptions($question, $questionData['options']);
            }
        }
    }

    private function createOptions(PollQuestion $question, array $options): void
    {
        $songIds = collect($options)->pluck('song_id')->filter();
        $artistIds = collect($options)->pluck('artist_id')->filter();

        $songs = $songIds->isNotEmpty()
            ? Song::whereIn('id', $songIds)->with('artist:id,stage_name')->get()->keyBy('id')
            : collect();

        $artists = $artistIds->isNotEmpty()
            ? Artist::whereIn('id', $artistIds)->get()->keyBy('id')
            : collect();

        foreach ($options as $position => $item) {
            $optionText = $item['option_text'] ?? null;

            if (! $optionText && isset($item['song_id'])) {
                $song = $songs->get($item['song_id']);
                $optionText = $song ? "{$song->title} – {$song->artist?->stage_name}" : "Track {$position}";
            }

            if (! $optionText && isset($item['artist_id'])) {
                $artist = $artists->get($item['artist_id']);
                $optionText = $artist?->stage_name ?? "Artist {$position}";
            }

            PollOption::create([
                'question_id' => $question->id,
                'option_text' => $optionText ?? "Option {$position}",
                'image' => $item['image'] ?? null,
                'position' => $position,
                'song_id' => $item['song_id'] ?? null,
                'artist_id' => $item['artist_id'] ?? null,
            ]);
        }
    }
}
