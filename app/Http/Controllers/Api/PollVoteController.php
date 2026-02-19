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
    /**
     * POST /api/polls/{poll}/vote
     * Cast a vote on a poll.
     */
    public function vote(Request $request, Poll $poll): JsonResponse
    {
        $user = $request->user();

        // Validate poll is active
        if (! $poll->isActive()) {
            return response()->json(['message' => 'This poll is no longer accepting votes.'], 422);
        }

        // Check if user already voted
        if ($poll->userHasVoted($user)) {
            return response()->json(['message' => 'You have already voted on this poll.'], 422);
        }

        // Validate option(s)
        $rules = $poll->allow_multiple_votes
            ? ['option_id' => 'required|array|min:1', 'option_id.*' => 'exists:poll_options,id']
            : ['option_id' => 'required|exists:poll_options,id'];

        $validated = $request->validate($rules);

        $optionIds = (array) $validated['option_id'];

        // Ensure all options belong to this poll
        $validOptions = $poll->options()->whereIn('id', $optionIds)->pluck('id');
        if ($validOptions->count() !== count($optionIds)) {
            return response()->json(['message' => 'One or more options do not belong to this poll.'], 422);
        }

        DB::transaction(function () use ($poll, $user, $optionIds) {
            foreach ($optionIds as $optionId) {
                PollVote::create([
                    'poll_id' => $poll->id,
                    'option_id' => $optionId,
                    'user_id' => $user->id,
                    'voted_at' => now(),
                ]);

                // Increment option vote count
                $poll->options()->where('id', $optionId)->increment('vote_count');
            }

            // Increment total votes on poll
            $poll->increment('total_votes', count($optionIds));
        });

        // Return updated poll
        $poll->load(['options', 'user', 'votes']);

        return response()->json([
            'data' => new PollResource($poll->fresh()->load(['options', 'user', 'votes'])),
            'message' => 'Vote recorded successfully.',
        ]);
    }

    /**
     * GET /api/polls/{poll}/results
     * View poll results (public — visibility governed by PollResource).
     */
    public function results(Request $request, Poll $poll)
    {
        $poll->load(['options', 'user', 'votes']);

        return new PollResource($poll);
    }
}
