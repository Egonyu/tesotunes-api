<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PollResource;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PollVoteController extends Controller
{
    private const VOTE_CREDITS_DAILY_POLL_LIMIT = 5;

    /**
     * POST /api/polls/{poll}/vote
     */
    public function vote(Request $request, Poll $poll): JsonResponse
    {
        $user = $request->user();

        if (! $poll->isActive()) {
            return response()->json(['message' => 'This poll is no longer accepting votes.'], 422);
        }

        if ($poll->userHasVoted($user)) {
            return response()->json(['message' => 'You have already voted on this poll.'], 422);
        }

        $rules = $poll->allow_multiple_votes
            ? ['option_id' => 'required|array|min:1', 'option_id.*' => 'exists:poll_options,id']
            : ['option_id' => 'required|exists:poll_options,id'];

        $validated = $request->validate($rules);
        $optionIds = (array) $validated['option_id'];

        $validOptions = $poll->options()->whereIn('id', $optionIds)->pluck('id');
        if ($validOptions->count() !== count($optionIds)) {
            return response()->json(['message' => 'One or more options do not belong to this poll.'], 422);
        }

        DB::transaction(function () use ($poll, $user, $optionIds) {
            foreach ($optionIds as $optionId) {
                PollVote::create([
                    'poll_id'  => $poll->id,
                    'option_id'=> $optionId,
                    'user_id'  => $user->id,
                    'voted_at' => now(),
                ]);

                $poll->options()->where('id', $optionId)->increment('vote_count');
            }

            $poll->increment('total_votes', count($optionIds));
        });

        $creditsEarned = $this->awardVoteCredits($user, $poll);

        $fresh = $poll->fresh()->load(['options.song.artist', 'options.artist', 'user', 'votes']);

        return response()->json([
            'data'           => new PollResource($fresh),
            'message'        => 'Vote recorded successfully.',
            'credits_earned' => $creditsEarned,
        ]);
    }

    /**
     * GET /api/polls/{poll}/results
     */
    public function results(Request $request, Poll $poll)
    {
        $poll->load(['options.song.artist', 'options.artist', 'user', 'votes']);

        return new PollResource($poll);
    }

    /**
     * GET /api/polls
     */
    public function index(Request $request)
    {
        $query = Poll::with(['options.song.artist', 'options.artist', 'user']);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['active', 'closed']);
        }

        if ($type = $request->get('poll_type')) {
            $query->where('poll_type', $type);
        }

        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        $polls = $query->orderByDesc('created_at')
            ->paginate($this->getPerPage($request, 10));

        if ($request->user()) {
            $polls->load('votes');
        }

        return PollResource::collection($polls);
    }

    private function awardVoteCredits($user, Poll $poll): int
    {
        $reward = max(1, (int) ($poll->credits_reward ?? 3));

        $todayPollVoteCount = PollVote::where('user_id', $user->id)
            ->whereDate('voted_at', today())
            ->distinct('poll_id')
            ->count('poll_id');

        if ($todayPollVoteCount > self::VOTE_CREDITS_DAILY_POLL_LIMIT) {
            return 0;
        }

        try {
            $user->addCredits(
                $reward,
                'poll_vote',
                "Voted in poll: {$poll->title}",
                ['poll_id' => $poll->id, 'poll_type' => $poll->poll_type]
            );

            return $reward;
        } catch (\Throwable) {
            return 0;
        }
    }
}
