<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePollRequest;
use App\Http\Resources\PollResource;
use App\Models\Artist;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use App\Models\Modules\Forum\PollResponse;
use App\Models\Song;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PollsApiController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/admin/polls/stats
     */
    public function stats(): Response
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:polls:stats', now()->addMinutes(5), function () {
                return [
                    'total_polls' => Poll::count(),
                    'active_polls' => Poll::where('status', Poll::STATUS_ACTIVE)->count(),
                    'closed_polls' => Poll::where('status', Poll::STATUS_CLOSED)->count(),
                    'draft_polls' => Poll::where('status', Poll::STATUS_DRAFT)->count(),
                    'research_surveys' => Poll::byType(Poll::TYPE_RESEARCH_SURVEY)->count(),
                    'song_battles' => Poll::byType(Poll::TYPE_SONG_BATTLE)->count(),
                    'artist_contests' => Poll::byType(Poll::TYPE_ARTIST_CONTEST)->count(),
                    'general_polls' => Poll::byType(Poll::TYPE_GENERAL)->count(),

                    'total_responses' => PollResponse::where('is_complete', true)->count(),
                    'total_guest_responses' => PollResponse::whereNull('user_id')->where('is_complete', true)->count(),
                    'total_user_responses' => PollResponse::whereNotNull('user_id')->where('is_complete', true)->count(),

                    'recent_polls_30d' => Poll::where('created_at', '>=', now()->subDays(30))->count(),
                    'responses_last_7d' => PollResponse::where('is_complete', true)
                        ->where('completed_at', '>=', now()->subDays(7))->count(),
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        }, 'Failed to retrieve poll stats.');
    }

    /**
     * GET /api/admin/polls
     */
    public function index(Request $request): Response
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->input('per_page', 15), 100);

            $polls = Poll::withCount(['questions', 'responses'])
                ->with(['user'])
                ->when($request->filled('search'), fn ($q) => $q->where(
                    'title', 'like', '%'.addcslashes($request->search, '%_').'%'
                ))
                ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('poll_type'), fn ($q) => $q->where('poll_type', $request->poll_type))
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
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
    public function show(int $id): Response
    {
        return $this->handleApiAction(function () use ($id) {
            $poll = Poll::with([
                'questions.options.song.artist',
                'questions.options.artist',
                'user',
            ])->withCount(['questions', 'responses'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new PollResource($poll),
            ]);
        }, 'Failed to retrieve poll.');
    }

    /**
     * POST /api/admin/polls
     *
     * Admin can create all poll types including research surveys.
     */
    public function store(StorePollRequest $request): Response
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validated();

            return DB::transaction(function () use ($validated, $request) {
                $poll = Poll::create([
                    'user_id' => $request->user()->id,
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'poll_type' => $validated['poll_type'],
                    'category' => $validated['category'] ?? null,
                    'audience' => $validated['audience'] ?? Poll::AUDIENCE_ALL,
                    'allow_guest_responses' => $validated['allow_guest_responses'] ?? true,
                    'show_results_before_completion' => $validated['show_results_before_completion'] ?? true,
                    'is_anonymous' => $validated['is_anonymous'] ?? false,
                    'credits_reward' => $validated['credits_reward'] ?? 3,
                    'starts_at' => $validated['starts_at'] ?? now(),
                    'ends_at' => $validated['ends_at'] ?? null,
                    'status' => $validated['status'] ?? Poll::STATUS_DRAFT,
                ]);

                $this->createQuestions($poll, $validated['questions']);

                Cache::forget('admin:polls:stats');

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
     * PUT /api/admin/polls/{id}
     *
     * Updates poll metadata only. Questions cannot be edited once responses exist.
     */
    public function update(Request $request, int $id): Response
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $poll = Poll::withCount('responses')->findOrFail($id);

            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'category' => ['nullable', 'string', 'in:'.implode(',', array_keys(Poll::CATEGORIES))],
                'audience' => ['nullable', 'string', 'in:all,users,artists'],
                'allow_guest_responses' => ['nullable', 'boolean'],
                'show_results_before_completion' => ['nullable', 'boolean'],
                'is_anonymous' => ['nullable', 'boolean'],
                'credits_reward' => ['nullable', 'integer', 'min:1', 'max:20'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date'],
                'status' => ['nullable', 'string', 'in:draft,active,closed,archived'],
            ]);

            $poll->update($validated);
            Cache::forget('admin:polls:stats');

            return response()->json([
                'success' => true,
                'message' => 'Poll updated.',
                'data' => new PollResource(
                    $poll->fresh(['questions.options.song.artist', 'questions.options.artist', 'user'])
                ),
            ]);
        }, 'Failed to update poll.');
    }

    /**
     * DELETE /api/admin/polls/{id}
     */
    public function destroy(int $id): Response
    {
        return $this->handleApiAction(function () use ($id) {
            Poll::findOrFail($id)->delete();
            Cache::forget('admin:polls:stats');

            return response()->json(['success' => true, 'message' => 'Poll deleted.']);
        }, 'Failed to delete poll.');
    }

    /**
     * POST /api/admin/polls/{id}/close
     */
    public function close(int $id): Response
    {
        return $this->handleApiAction(function () use ($id) {
            Poll::findOrFail($id)->close();

            return response()->json(['success' => true, 'message' => 'Poll closed.']);
        }, 'Failed to close poll.');
    }

    /**
     * POST /api/admin/polls/{id}/reopen
     */
    public function reopen(int $id): Response
    {
        return $this->handleApiAction(function () use ($id) {
            Poll::findOrFail($id)->activate();

            return response()->json(['success' => true, 'message' => 'Poll reopened.']);
        }, 'Failed to reopen poll.');
    }

    /**
     * GET /api/admin/polls/{id}/analytics
     *
     * Per-question breakdown with response counts, rating averages, and text samples.
     */
    public function analytics(int $id): Response
    {
        return $this->handleApiAction(function () use ($id) {
            $poll = Poll::with(['questions.options'])->withCount('responses')->findOrFail($id);

            $totalResponses = $poll->responses_count;
            $completedResponses = $poll->responses()->where('is_complete', true)->count();
            $guestResponses = $poll->responses()->whereNull('user_id')->where('is_complete', true)->count();

            $questions = $poll->questions->map(function (PollQuestion $question) use ($totalResponses) {
                $answeredCount = $question->answers()->distinct('response_id')->count('response_id');

                $breakdown = match ($question->question_type) {
                    PollQuestion::TYPE_MULTIPLE_CHOICE, PollQuestion::TYPE_RANKING => $question->options->map(fn (PollOption $option) => [
                        'option_id' => $option->id,
                        'option_text' => $option->option_text,
                        'response_count' => $option->response_count,
                        'percentage' => $answeredCount > 0
                            ? round(($option->response_count / $answeredCount) * 100, 1)
                            : 0.0,
                    ])->values(),

                    PollQuestion::TYPE_RATING, PollQuestion::TYPE_LIKERT => [
                        'average' => round(
                            (float) $question->answers()->whereNotNull('rating_value')->avg('rating_value') ?? 0,
                            2
                        ),
                        'distribution' => $question->answers()
                            ->whereNotNull('rating_value')
                            ->selectRaw('rating_value, COUNT(*) as count')
                            ->groupBy('rating_value')
                            ->orderBy('rating_value')
                            ->pluck('count', 'rating_value'),
                        'scale' => [
                            'min' => $question->scaleMin(),
                            'max' => $question->scaleMax(),
                            'min_label' => $question->settings['min_label'] ?? null,
                            'max_label' => $question->settings['max_label'] ?? null,
                        ],
                    ],

                    PollQuestion::TYPE_FREE_TEXT => [
                        'total_answers' => $answeredCount,
                        'sample' => $question->answers()
                            ->whereNotNull('answer_text')
                            ->latest()
                            ->limit(10)
                            ->pluck('answer_text'),
                    ],

                    default => null,
                };

                return [
                    'question_id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'answered_count' => $answeredCount,
                    'skip_rate' => $totalResponses > 0
                        ? round((($totalResponses - $answeredCount) / $totalResponses) * 100, 1)
                        : 0.0,
                    'breakdown' => $breakdown,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'poll_id' => $poll->id,
                    'title' => $poll->title,
                    'poll_type' => $poll->poll_type,
                    'status' => $poll->status,
                    'total_responses' => $totalResponses,
                    'completed_responses' => $completedResponses,
                    'guest_responses' => $guestResponses,
                    'user_responses' => $completedResponses - $guestResponses,
                    'completion_rate' => $totalResponses > 0
                        ? round(($completedResponses / $totalResponses) * 100, 1)
                        : 0.0,
                    'questions' => $questions,
                ],
            ]);
        }, 'Failed to retrieve poll analytics.');
    }

    /**
     * GET /api/admin/polls/{id}/export
     *
     * Streams a CSV of all answers — one row per response, one column per question.
     */
    public function export(int $id): Response
    {
        return $this->handleApiAction(function () use ($id) {
            $poll = Poll::with(['questions.options'])->findOrFail($id);
            $questions = $poll->questions;

            $filename = 'poll_'.$poll->id.'_'.now()->format('Ymd_His').'.csv';

            return response()->streamDownload(function () use ($poll, $questions) {
                $handle = fopen('php://output', 'w');

                // Header row
                $headers = ['response_id', 'respondent_type', 'completed_at', 'ip_address'];
                foreach ($questions as $question) {
                    $headers[] = "Q{$question->position}: ".substr($question->question_text, 0, 60);
                }
                fputcsv($handle, $headers);

                // Stream responses in chunks to avoid memory exhaustion
                $poll->responses()
                    ->with(['answers.option', 'answers.question'])
                    ->where('is_complete', true)
                    ->orderBy('id')
                    ->chunk(200, function ($responses) use ($handle, $questions) {
                        foreach ($responses as $response) {
                            /** @var PollResponse $response */
                            $row = [
                                $response->id,
                                $response->isGuest() ? 'guest' : 'user',
                                $response->completed_at?->toIso8601String(),
                                $response->ip_address,
                            ];

                            foreach ($questions as $question) {
                                $answers = $response->answers->where('question_id', $question->id);

                                $row[] = match ($question->question_type) {
                                    PollQuestion::TYPE_MULTIPLE_CHOICE, PollQuestion::TYPE_RANKING => $answers->map(fn ($a) => $a->option?->option_text ?? "Option #{$a->option_id}")->implode('; '),
                                    PollQuestion::TYPE_FREE_TEXT => $answers->first()?->answer_text ?? '',
                                    PollQuestion::TYPE_RATING, PollQuestion::TYPE_LIKERT => $answers->first()?->rating_value ?? '',
                                    default => '',
                                };
                            }

                            fputcsv($handle, $row);
                        }
                    });

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }, 'Failed to export poll data.');
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
