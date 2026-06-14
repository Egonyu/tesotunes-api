<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commerce\Settlement;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Models\CorpusPair;
use App\Modules\Contributions\Services\CorpusExportService;
use App\Modules\Contributions\Services\TaskAuthoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator surface for the corpus pipeline: health, daily-pool spend, the
 * review backlog, gold-item seeding, and on-demand export. Admin-only.
 */
class ContributionAdminController extends Controller
{
    /**
     * GET /api/contributions/admin/overview — corpus + pipeline health.
     */
    public function overview(): JsonResponse
    {
        $poolSpentToday = (int) Settlement::query()
            ->where('vertical', Settlement::VERTICAL_CONTRIBUTIONS)
            ->whereDate('created_at', today())
            ->sum('gross_credits');

        return response()->json([
            'success' => true,
            'data' => [
                'corpus' => [
                    'total_pairs' => CorpusPair::count(),
                    'by_region' => CorpusPair::query()->selectRaw('region, COUNT(*) c')->groupBy('region')->pluck('c', 'region'),
                    'by_register' => CorpusPair::query()->selectRaw('register, COUNT(*) c')->groupBy('register')->pluck('c', 'register'),
                    'exported' => CorpusPair::query()->whereNotNull('exported_at')->count(),
                ],
                'tasks' => [
                    'open' => ContributionTask::where('status', ContributionTask::STATUS_OPEN)->count(),
                    'fulfilled' => ContributionTask::where('status', ContributionTask::STATUS_FULFILLED)->count(),
                    'gold' => ContributionTask::where('is_gold', true)->count(),
                ],
                'submissions' => [
                    'awaiting_validation' => ContributionSubmission::where('status', ContributionSubmission::STATUS_SUBMITTED)->count(),
                    'accepted' => ContributionSubmission::where('status', ContributionSubmission::STATUS_ACCEPTED)->count(),
                ],
                'contributors' => [
                    'total' => ContributorProfile::count(),
                    'by_tier' => ContributorProfile::query()->selectRaw('tier, COUNT(*) c')->groupBy('tier')->pluck('c', 'tier'),
                ],
                'rewards' => [
                    'daily_pool' => (int) config('contributions.rewards.daily_pool_ugx'),
                    'pool_spent_today' => $poolSpentToday,
                    'pool_remaining_today' => max(0, (int) config('contributions.rewards.daily_pool_ugx') - $poolSpentToday),
                ],
            ],
        ]);
    }

    /**
     * POST /api/contributions/admin/gold — seed a gold-standard item (known
     * answer, hidden) that gets salted into the contributor stream.
     */
    public function seedGold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt_text' => ['required', 'string', 'max:2000'],
            'gold_answer' => ['required', 'string', 'max:2000'],
            // Additional accepted forms (dialectal variants) so a valid variant
            // never fails a gold.
            'gold_answers' => ['sometimes', 'array', 'max:20'],
            'gold_answers.*' => ['string', 'max:2000'],
            'source_lang' => ['sometimes', 'string', 'max:8'],
            'target_lang' => ['sometimes', 'string', 'max:8'],
            'region' => ['sometimes', 'string', 'max:8'],
            'register' => ['sometimes', 'string', 'max:40'],
        ]);

        $task = ContributionTask::create([
            'type' => ContributionTask::TYPE_TRANSLATE,
            'source_lang' => $validated['source_lang'] ?? config('contributions.languages.target'),
            'target_lang' => $validated['target_lang'] ?? config('contributions.languages.source'),
            'region' => $validated['region'] ?? config('contributions.default_region'),
            'register' => $validated['register'] ?? null,
            'prompt_text' => $validated['prompt_text'],
            'is_gold' => true,
            'gold_answer' => $validated['gold_answer'],
            'gold_answers' => $validated['gold_answers'] ?? null,
            'redundancy_target' => (int) config('contributions.redundancy_target', 3),
            'status' => ContributionTask::STATUS_OPEN,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gold item seeded.',
            'data' => ['uuid' => $task->uuid],
        ], 201);
    }

    /**
     * GET /api/contributions/admin/tasks — browse/manage the curated task pool.
     */
    public function tasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'register' => ['sometimes', 'string'],
            'gold' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tasks = ContributionTask::query()
            ->where('type', ContributionTask::TYPE_TRANSLATE)
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['register'] ?? null, fn ($q, $r) => $q->where('register', $r))
            ->when(array_key_exists('gold', $validated), fn ($q) => $q->where('is_gold', $validated['gold']))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => $tasks->getCollection()->map(fn (ContributionTask $task) => [
                'uuid' => $task->uuid,
                'prompt_text' => $task->prompt_text,
                'source_lang' => $task->source_lang,
                'target_lang' => $task->target_lang,
                'register' => $task->register,
                'is_gold' => (bool) $task->is_gold,
                'status' => $task->status,
                'submission_count' => $task->submission_count,
            ])->all(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * POST /api/contributions/admin/tasks/import — bulk-author curated prompts.
     * The primary way to fill and steer the task pool.
     */
    public function importTasks(Request $request, TaskAuthoringService $authoring): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['required', 'in:en_to_teo,teo_to_en'],
            'register' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:8'],
            'prompts' => ['required', 'array', 'min:1', 'max:1000'],
            'prompts.*' => ['string', 'max:2000'],
        ]);

        $result = $authoring->import(
            $validated['prompts'],
            $validated['direction'],
            $validated['register'] ?? null,
            $validated['region'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => "Created {$result['created']} task(s), skipped {$result['skipped']} duplicate(s).",
            'data' => $result,
        ], 201);
    }

    /**
     * POST /api/contributions/admin/tasks/{task}/close — retire a task.
     */
    public function closeTask(string $task): JsonResponse
    {
        $taskModel = ContributionTask::query()->where('uuid', $task)->firstOrFail();
        $taskModel->forceFill(['status' => ContributionTask::STATUS_CLOSED])->save();

        return response()->json(['success' => true, 'message' => 'Task closed.']);
    }

    /**
     * POST /api/contributions/admin/export — run a versioned corpus export.
     */
    public function export(Request $request, CorpusExportService $exporter): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['sometimes', 'string', 'max:30'],
        ]);

        $result = $exporter->export($validated['version'] ?? null);

        return response()->json([
            'success' => true,
            'message' => "Exported {$result['count']} pair(s).",
            'data' => $result,
        ]);
    }
}
