<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PollsApiController extends Controller
{
    /**
     * GET /api/admin/polls/stats
     */
    public function stats()
    {
        return response()->json([
            'data' => [
                'total_polls' => Poll::count(),
                'active_polls' => Poll::where('status', 'active')->count(),
                'closed_polls' => Poll::where('status', 'closed')->count(),
                'total_votes' => DB::table('poll_votes')->count(),
                'recent_polls_30d' => Poll::where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    /**
     * GET /api/admin/polls
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);

        $polls = Poll::with(['user', 'options'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('title', 'like', '%'.$request->search.'%');
            })
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($polls);
    }

    /**
     * GET /api/admin/polls/{id}
     */
    public function show(int $id)
    {
        $poll = Poll::with(['user', 'options.votes'])->findOrFail($id);

        return response()->json(['data' => $poll]);
    }

    /**
     * POST /api/admin/polls
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'options' => 'required|array|min:2|max:10',
            'options.*' => 'required|string|max:255',
            'allow_multiple_votes' => 'boolean',
            'show_results_before_vote' => 'boolean',
            'is_anonymous' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'status' => 'in:active,draft,closed',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $poll = Poll::create([
                'user_id' => $request->user()->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'allow_multiple_votes' => $validated['allow_multiple_votes'] ?? false,
                'show_results_before_vote' => $validated['show_results_before_vote'] ?? true,
                'is_anonymous' => $validated['is_anonymous'] ?? false,
                'starts_at' => $validated['starts_at'] ?? now(),
                'ends_at' => $validated['ends_at'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ]);

            foreach ($validated['options'] as $index => $optionText) {
                PollOption::create([
                    'poll_id' => $poll->id,
                    'option_text' => $optionText,
                    'position' => $index,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Poll created successfully',
                'data' => $poll->load('options'),
            ], 201);
        });
    }

    /**
     * PUT /api/admin/polls/{id}
     */
    public function update(Request $request, int $id)
    {
        $poll = Poll::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
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

        // Replace options if provided (only when no votes cast)
        if (isset($validated['options'])) {
            if ($poll->total_votes > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change options after votes have been cast',
                ], 422);
            }

            $poll->options()->delete();
            foreach ($validated['options'] as $index => $optionText) {
                PollOption::create([
                    'poll_id' => $poll->id,
                    'option_text' => $optionText,
                    'position' => $index,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Poll updated successfully',
            'data' => $poll->fresh(['options']),
        ]);
    }

    /**
     * DELETE /api/admin/polls/{id}
     */
    public function destroy(int $id)
    {
        $poll = Poll::findOrFail($id);
        $poll->delete();

        return response()->json(['success' => true, 'message' => 'Poll deleted successfully']);
    }

    /**
     * POST /api/admin/polls/{id}/close
     */
    public function close(int $id)
    {
        $poll = Poll::findOrFail($id);
        $poll->close();

        return response()->json([
            'success' => true,
            'message' => 'Poll closed successfully',
        ]);
    }

    /**
     * POST /api/admin/polls/{id}/reopen
     */
    public function reopen(int $id)
    {
        $poll = Poll::findOrFail($id);
        $poll->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Poll reopened successfully',
        ]);
    }
}
