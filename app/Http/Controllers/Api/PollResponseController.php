<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPollResponseRequest;
use App\Http\Resources\PollResource;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollAnswer;
use App\Models\Modules\Forum\PollOption;
use App\Models\Modules\Forum\PollQuestion;
use App\Models\Modules\Forum\PollResponse;
use App\Traits\HandlesApiErrors;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PollResponseController extends Controller
{
    use HandlesApiErrors;

    private const CREDITS_DAILY_POLL_LIMIT = 5;

    /**
     * POST /api/polls/{poll}/respond
     *
     * Accepts responses from authenticated users and guests alike.
     * Guests are identified by a 64-char session token stored in a cookie.
     */
    public function respond(SubmitPollResponseRequest $request, Poll $poll): Response
    {
        return $this->handleApiAction(function () use ($request, $poll) {
            if (! $poll->isActive()) {
                return response()->json(['message' => 'This poll is no longer accepting responses.'], 422);
            }

            $user = $request->user();
            $sessionToken = $this->resolveSessionToken($request, $poll);

            // Duplicate submission guard
            if ($user && $poll->hasUserResponded($user->id)) {
                return response()->json(['message' => 'You have already responded to this poll.'], 422);
            }

            if (! $user && $sessionToken && $poll->hasGuestResponded($sessionToken)) {
                return response()->json(['message' => 'You have already responded to this poll.'], 422);
            }

            if (! $user && ! $poll->allow_guest_responses) {
                return response()->json(['message' => 'You must be signed in to respond to this poll.'], 401);
            }

            $validated = $request->validated();

            // Load the poll's questions for validation
            $questions = $poll->questions()
                ->with('options')
                ->get()
                ->keyBy('id');

            $answersInput = collect($validated['answers']);

            // Validate required questions are answered
            $requiredQuestionIds = $questions->where('is_required', true)->keys();
            $answeredQuestionIds = $answersInput->pluck('question_id');

            $missing = $requiredQuestionIds->diff($answeredQuestionIds);
            if ($missing->isNotEmpty()) {
                return response()->json([
                    'message' => 'Please answer all required questions.',
                    'missing_question_ids' => $missing->values(),
                ], 422);
            }

            $creditsEarned = 0;

            DB::transaction(function () use ($poll, $user, $sessionToken, $questions, $answersInput, &$creditsEarned) {
                $pollResponse = PollResponse::create([
                    'poll_id' => $poll->id,
                    'user_id' => $user?->id,
                    'session_token' => $user ? null : $sessionToken,
                    'ip_address' => request()->ip(),
                    'started_at' => now(),
                    'is_complete' => false,
                ]);

                foreach ($answersInput as $answerInput) {
                    $question = $questions->get($answerInput['question_id']);

                    if (! $question || $question->poll_id !== $poll->id) {
                        continue;
                    }

                    $this->recordAnswer($pollResponse, $question, $answerInput);
                }

                $pollResponse->complete();
                $poll->increment('total_responses');

                if ($user && $poll->isCommunityPoll()) {
                    $creditsEarned = $this->awardCredits($user, $poll);
                }
            });

            $fresh = $poll->fresh()->load(['questions.options.song.artist', 'questions.options.artist', 'user']);

            $response = response()->json([
                'success' => true,
                'message' => 'Response recorded. Thank you!',
                'data' => new PollResource($fresh),
                'credits_earned' => $creditsEarned,
            ]);

            // Set guest cookie if this was a guest response
            if (! $user && $sessionToken) {
                $response->withCookie(
                    Cookie::make('poll_session_token', $sessionToken, 60 * 24 * 365, '/', null, true, true)
                );
            }

            return $response;
        }, 'Failed to record response.');
    }

    // ── Private helpers ────────────────────────────────────────────

    private function resolveSessionToken(SubmitPollResponseRequest $request, Poll $poll): ?string
    {
        if ($request->user()) {
            return null;
        }

        // Use token from cookie first, then request body, then generate a new one
        return $request->cookie('poll_session_token')
            ?? $request->input('session_token')
            ?? Str::random(64);
    }

    private function recordAnswer(PollResponse $pollResponse, PollQuestion $question, array $input): void
    {
        match ($question->question_type) {
            PollQuestion::TYPE_MULTIPLE_CHOICE => $this->recordChoiceAnswer($pollResponse, $question, $input),
            PollQuestion::TYPE_RANKING => $this->recordRankingAnswer($pollResponse, $question, $input),
            PollQuestion::TYPE_FREE_TEXT => $this->recordFreeTextAnswer($pollResponse, $question, $input),
            PollQuestion::TYPE_RATING, PollQuestion::TYPE_LIKERT => $this->recordRatingAnswer($pollResponse, $question, $input),
            default => null,
        };
    }

    private function recordChoiceAnswer(PollResponse $pollResponse, PollQuestion $question, array $input): void
    {
        $optionIds = (array) ($input['option_ids'] ?? []);

        if (! $question->allow_multiple) {
            $optionIds = array_slice($optionIds, 0, 1);
        }

        // Verify options belong to this question
        $validOptionIds = $question->options->pluck('id')->intersect($optionIds);

        foreach ($validOptionIds as $optionId) {
            PollAnswer::create([
                'response_id' => $pollResponse->id,
                'question_id' => $question->id,
                'option_id' => $optionId,
            ]);

            PollOption::where('id', $optionId)->increment('response_count');
        }
    }

    private function recordRankingAnswer(PollResponse $pollResponse, PollQuestion $question, array $input): void
    {
        $ranking = $input['ranking'] ?? [];
        $validOptionIds = $question->options->pluck('id');

        foreach ($ranking as $rank) {
            $optionId = $rank['option_id'] ?? null;

            if (! $optionId || ! $validOptionIds->contains($optionId)) {
                continue;
            }

            PollAnswer::create([
                'response_id' => $pollResponse->id,
                'question_id' => $question->id,
                'option_id' => $optionId,
                'rank_position' => (int) ($rank['position'] ?? 0),
            ]);
        }
    }

    private function recordFreeTextAnswer(PollResponse $pollResponse, PollQuestion $question, array $input): void
    {
        $text = trim($input['answer_text'] ?? '');

        if ($text === '') {
            return;
        }

        PollAnswer::create([
            'response_id' => $pollResponse->id,
            'question_id' => $question->id,
            'answer_text' => $text,
        ]);
    }

    private function recordRatingAnswer(PollResponse $pollResponse, PollQuestion $question, array $input): void
    {
        $value = $input['rating_value'] ?? null;

        if ($value === null) {
            return;
        }

        $value = max($question->scaleMin(), min($question->scaleMax(), (int) $value));

        PollAnswer::create([
            'response_id' => $pollResponse->id,
            'question_id' => $question->id,
            'rating_value' => $value,
        ]);
    }

    private function awardCredits($user, Poll $poll): int
    {
        $reward = max(1, (int) ($poll->credits_reward ?? 3));

        $todayCount = PollResponse::where('user_id', $user->id)
            ->whereDate('started_at', today())
            ->where('is_complete', true)
            ->distinct('poll_id')
            ->count('poll_id');

        if ($todayCount > self::CREDITS_DAILY_POLL_LIMIT) {
            return 0;
        }

        try {
            $user->addCredits(
                $reward,
                'poll_response',
                "Responded to poll: {$poll->title}",
                ['poll_id' => $poll->id, 'poll_type' => $poll->poll_type]
            );

            return $reward;
        } catch (\Throwable) {
            return 0;
        }
    }
}
