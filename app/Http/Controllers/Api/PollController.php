<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PollResource;
use App\Models\Modules\Forum\Poll;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PollController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/polls
     *
     * Public listing — guests see active polls, authenticated users also get
     * their responded state hydrated via the PollResource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->get('per_page', 12), 50);

        $query = Poll::with(['questions.options.song.artist', 'questions.options.artist', 'user'])
            ->published();

        if ($type = $request->get('poll_type')) {
            $query->byType($type);
        }

        if ($category = $request->get('category')) {
            $query->byCategory($category);
        }

        // Guests only see polls open to all; authenticated users see everything published
        if (! $request->user()) {
            $query->forAudience(Poll::AUDIENCE_ALL);
        }

        $polls = $query->orderByDesc('created_at')->paginate($perPage);

        return PollResource::collection($polls);
    }

    /**
     * GET /api/polls/{poll}
     *
     * Full poll detail with all questions and options.
     */
    public function show(Request $request, Poll $poll): PollResource|JsonResponse
    {
        if (! in_array($poll->status, [Poll::STATUS_ACTIVE, Poll::STATUS_CLOSED], true)) {
            return response()->json(['message' => 'Poll not found.'], 404);
        }

        $poll->load(['questions.options.song.artist', 'questions.options.artist', 'user']);

        return new PollResource($poll);
    }

    /**
     * GET /api/polls/{poll}/results
     *
     * Detailed results — forces showResults = true regardless of settings.
     * Only available once a poll is closed or the respondent has completed it.
     */
    public function results(Request $request, Poll $poll): PollResource|JsonResponse
    {
        $poll->load(['questions.options.song.artist', 'questions.options.artist', 'user']);

        $user = $request->user();
        $sessionToken = $request->cookie('poll_session_token');

        $hasResponded = match (true) {
            $user !== null => $poll->hasUserResponded($user->id),
            $sessionToken !== null => $poll->hasGuestResponded($sessionToken),
            default => false,
        };

        $canViewResults = $poll->status === Poll::STATUS_CLOSED
            || $hasResponded
            || (bool) $poll->show_results_before_completion;

        if (! $canViewResults) {
            return response()->json(['message' => 'Results are available after completing this poll.'], 403);
        }

        // Force results visible for this response
        $original = $poll->show_results_before_completion;
        $poll->show_results_before_completion = true;

        $resource = new PollResource($poll);

        $poll->show_results_before_completion = $original;

        return $resource;
    }
}
