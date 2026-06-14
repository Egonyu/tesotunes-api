<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Services\ValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Peer validation surface: review other contributors' translations and cast a
 * verdict. The queue already filters out your own work, work you've reviewed,
 * and contributors you referred.
 */
class ContributionValidationController extends Controller
{
    public function __construct(private readonly ValidationService $validations) {}

    /**
     * GET /api/contributions/validations/queue — submissions to review.
     */
    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->get('per_page', 20), 50);

        $items = ContributionSubmission::query()
            ->with('task:id,uuid,prompt_text,source_lang,target_lang,register')
            ->where('status', ContributionSubmission::STATUS_SUBMITTED)
            ->where('user_id', '!=', $user->id)
            ->whereHas('task', fn ($q) => $q->where('type', ContributionTask::TYPE_TRANSLATE)->where('is_gold', false))
            ->whereDoesntHave('validations', fn ($q) => $q->where('validator_user_id', $user->id))
            ->whereDoesntHave('user', fn ($q) => $q->where('referrer_id', $user->id))
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items->getCollection()->map(fn (ContributionSubmission $s) => [
                'submission_uuid' => $s->uuid,
                'source_text' => $s->task?->prompt_text,
                'translation' => $s->raw_text,
                'source_lang' => $s->task?->source_lang,
                'target_lang' => $s->task?->target_lang,
                'register' => $s->task?->register,
            ])->all(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * POST /api/contributions/submissions/{submission}/validate
     */
    public function store(Request $request, string $submission): JsonResponse
    {
        $validated = $request->validate([
            'verdict' => ['required', 'string', 'in:agree,minor_fix,valid_variant,reject'],
            'suggested_fix' => ['nullable', 'string', 'max:2000'],
        ]);

        $submissionModel = ContributionSubmission::query()->where('uuid', $submission)->firstOrFail();

        try {
            $validation = $this->validations->validate(
                $request->user(),
                $submissionModel,
                $validated['verdict'],
                $validated['suggested_fix'] ?? null,
            );
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verdict recorded. Thank you for reviewing.',
            'data' => [
                'uuid' => $validation->uuid,
                'verdict' => $validation->verdict,
            ],
        ], 201);
    }
}
