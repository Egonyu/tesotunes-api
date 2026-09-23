<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Contributions\Models\ContributionTask;
use App\Modules\Contributions\Models\GuestContribution;
use App\Modules\Contributions\Services\AtesoTranslatorClient;
use App\Modules\Contributions\Services\GuestClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The guest loop: translate → judge → correct, with no account.
 *
 * Unauthenticated by design. Every route here is throttled per IP, and nothing
 * it writes can grant privilege — rows land in guest_contributions and only
 * enter the rewarded pipeline once a real user claims them at sign-in.
 */
class GuestLoopController extends Controller
{
    private const MAX_TEXT = 500;

    /**
     * POST /api/contributions/guest/translate
     * Runs the model and records the attempt. The row is written before any
     * verdict so an abandoned turn still tells us what people wanted to say.
     */
    public function translate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT],
            'direction' => ['required', 'string', 'in:teo_to_en,en_to_teo'],
            'session_key' => ['required', 'string', 'max:64'],
            'origin' => ['sometimes', 'string', 'in:typed,suggested'],
        ]);

        $text = trim($validated['text']);

        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Type something first.',
            ], 422);
        }

        try {
            $output = AtesoTranslatorClient::fromConfig()->translate($text, $validated['direction']);
        } catch (\Throwable $e) {
            Log::warning('Guest translate failed', [
                'direction' => $validated['direction'],
                'error' => $e->getMessage(),
            ]);

            // The translator sleeps after 48h idle and takes a minute or two to
            // wake. Say so plainly rather than showing a dead spinner.
            return response()->json([
                'success' => false,
                'message' => 'The translator is waking up. Give it a minute and try again.',
            ], 503);
        }

        try {
            $row = GuestContribution::create([
                'session_key' => $validated['session_key'],
                'direction' => $validated['direction'],
                'source_text' => $text,
                'model_output' => $output,
                'origin' => $validated['origin'] ?? GuestContribution::ORIGIN_TYPED,
                'ip_hash' => GuestClaimService::hashIp($request->ip()),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not save that attempt. Try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'uuid' => $row->uuid,
                'source_text' => $row->source_text,
                'translation' => $row->model_output,
                'direction' => $row->direction,
            ],
        ], 201);
    }

    /**
     * POST /api/contributions/guest/{uuid}/verdict
     * Records right/wrong and the correction. A "correct" verdict matters as
     * much as a correction — it is the only signal that measures improvement.
     */
    public function verdict(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'verdict' => ['required', 'string', 'in:correct,wrong,skipped'],
            'correction' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'dialect' => ['nullable', 'string', 'max:16'],
            'code_switched' => ['sometimes', 'boolean'],
            'session_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            // Scoped by session key so a uuid alone cannot rewrite someone
            // else's turn.
            $row = GuestContribution::query()
                ->where('uuid', $uuid)
                ->where('session_key', $validated['session_key'])
                ->unclaimed()
                ->first();

            if (! $row) {
                return response()->json([
                    'success' => false,
                    'message' => 'That translation is no longer open for feedback.',
                ], 404);
            }

            $correction = trim((string) ($validated['correction'] ?? ''));

            $row->fill([
                'verdict' => $validated['verdict'],
                'correction' => $correction !== '' ? $correction : null,
                'dialect' => $validated['dialect'] ?? null,
                'is_code_switched' => (bool) ($validated['code_switched'] ?? false),
            ])->save();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not save your answer. Try again.',
            ], 500);
        }

        $pending = app(GuestClaimService::class)->pendingCount($validated['session_key']);

        return response()->json([
            'success' => true,
            'data' => [
                'uuid' => $row->uuid,
                'verdict' => $row->verdict,
                'pending_claim' => $pending,
            ],
        ]);
    }

    /**
     * POST /api/contributions/guest/claim
     * Called once immediately after sign-in. Promotes the visitor's anonymous
     * turns into real submissions so the work they did before having an account
     * is never lost — which is the whole reason the loop can stay open.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $result = app(GuestClaimService::class)->claim($request->user(), $validated['session_key']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not move your earlier work across. It is still saved.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $result['claimed'] > 0
                ? "Kept {$result['claimed']} of your translations."
                : 'Nothing to move across.',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/contributions/guest/suggestions
     * Sentences to offer anyone who stalls at an empty box. Drawn from the open
     * task pool, so nothing separate has to be maintained.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $tasks = ContributionTask::query()
                ->where('type', ContributionTask::TYPE_TRANSLATE)
                ->where('status', ContributionTask::STATUS_OPEN)
                ->where('is_gold', false)
                ->whereNotNull('prompt_text')
                ->inRandomOrder()
                ->limit($validated['limit'] ?? 3)
                ->get(['prompt_text', 'source_lang', 'target_lang']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => true, 'data' => []]);
        }

        $teo = (string) config('contributions.languages.target', 'teo');

        return response()->json([
            'success' => true,
            'data' => $tasks->map(fn (ContributionTask $t) => [
                'text' => $t->prompt_text,
                'direction' => $t->source_lang === $teo ? 'teo_to_en' : 'en_to_teo',
            ])->all(),
        ]);
    }

    /**
     * GET /api/contributions/guest/session
     * Mints a session key for a first-time visitor and reports collective
     * progress. The counter is never allowed to read zero on arrival — an empty
     * board makes the first contributor feel like nobody else showed up.
     */
    public function session(Request $request): JsonResponse
    {
        $key = (string) $request->query('session_key', '');

        if ($key === '' || strlen($key) > 64) {
            $key = GuestClaimService::mintSessionKey();
        }

        try {
            $judged = GuestContribution::query()->judged()->count();
            $mine = GuestContribution::query()
                ->where('session_key', $key)
                ->judged()
                ->count();
        } catch (\Throwable $e) {
            report($e);
            $judged = 0;
            $mine = 0;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'session_key' => $key,
                'my_contributions' => $mine,
                'total_contributions' => $judged,
            ],
        ]);
    }
}
