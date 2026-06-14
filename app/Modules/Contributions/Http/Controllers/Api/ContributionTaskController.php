<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contributor-facing translation tasks: browse what's open and submit answers.
 * Gold answers are never serialised (hidden on the model).
 */
class ContributionTaskController extends Controller
{
    public function __construct(private readonly SubmissionService $submissions) {}

    /**
     * GET /api/contributions/tasks — open translate tasks the user can answer,
     * optionally scoped to one song. Excludes tasks the user already answered.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $userId = $request->user()->id;

        $tasks = ContributionTask::query()
            ->where('type', ContributionTask::TYPE_TRANSLATE)
            ->where('status', ContributionTask::STATUS_OPEN)
            ->when($validated['song_id'] ?? null, fn ($q, $songId) => $q
                ->where('source_type', (new \App\Models\Song)->getMorphClass())
                ->where('source_id', $songId))
            ->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $tasks->getCollection()->map(fn (ContributionTask $task) => [
                'uuid' => $task->uuid,
                'prompt_text' => $task->prompt_text,
                'source_lang' => $task->source_lang,
                'target_lang' => $task->target_lang,
                'register' => $task->register,
                'region' => $task->region,
            ])->all(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * POST /api/contributions/tasks/{task}/submit — submit a translation.
     */
    public function submit(Request $request, string $task): JsonResponse
    {
        $validated = $request->validate([
            'translation' => ['required', 'string', 'max:2000'],
        ]);

        $taskModel = ContributionTask::query()->where('uuid', $task)->firstOrFail();

        try {
            $submission = $this->submissions->submit($request->user(), $taskModel, $validated['translation']);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Translation submitted. Thank you!',
            'data' => [
                'uuid' => $submission->uuid,
                'status' => $submission->status,
            ],
        ], 201);
    }
}
