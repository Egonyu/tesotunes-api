<?php

namespace App\Modules\Contributions\Services;

use App\Models\User;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\GuestContribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Promotes anonymous guest-loop work into the normal contribution pipeline the
 * moment its author signs in.
 *
 * A guest types their own sentence, so there is no task to attach the answer to.
 * We create one — which is the useful side effect: their sentence becomes a task
 * everyone else can translate too, and the existing redundancy/validation
 * machinery has something real to work on. Corpus material that came from a
 * speaker deciding what they wanted to say is worth more than anything we could
 * have picked in advance.
 */
class GuestClaimService
{
    public function __construct(
        private readonly TaskAuthoringService $authoring,
        private readonly SubmissionService $submissions,
    ) {}

    /**
     * Claim every unclaimed, judged row for a session key.
     *
     * @return array{claimed: int, skipped: int}
     */
    public function claim(User $user, string $sessionKey): array
    {
        $rows = GuestContribution::query()
            ->where('session_key', $sessionKey)
            ->unclaimed()
            ->judged()
            ->orderBy('id')
            ->get();

        $claimed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $answer = $row->humanAnswer();

            // A "wrong" verdict with no correction is a useful quality signal but
            // carries no corpus pair, so it stays put rather than entering the
            // submission pipeline with empty text.
            if ($answer === null) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($user, $row, $answer, &$claimed) {
                    // Idempotent on (prompt_text, source_lang) — a sentence several
                    // guests happened to type collapses to one shared task.
                    $task = $this->authoring->create(
                        $row->source_text,
                        $row->direction,
                        'guest',
                    );

                    // One submission per contributor per task is enforced by a
                    // unique index; a repeat is a no-op rather than an error.
                    $exists = ContributionSubmission::query()
                        ->where('contribution_task_id', $task->id)
                        ->where('user_id', $user->id)
                        ->exists();

                    if (! $exists) {
                        $this->submissions->submit($user, $task, $answer, [
                            'dialect' => $row->dialect,
                            'code_switched' => $row->is_code_switched,
                            'note' => 'Claimed from guest loop',
                        ]);
                    }

                    $row->forceFill([
                        'claimed_by_user_id' => $user->id,
                        'claimed_at' => now(),
                    ])->save();

                    $claimed++;
                });
            } catch (\Throwable $e) {
                // One bad row must not strand the rest of someone's work.
                report($e);
                $skipped++;
            }
        }

        return ['claimed' => $claimed, 'skipped' => $skipped];
    }

    /** How much unclaimed work is waiting for this session — drives the prompt. */
    public function pendingCount(string $sessionKey): int
    {
        return GuestContribution::query()
            ->where('session_key', $sessionKey)
            ->unclaimed()
            ->judged()
            ->count();
    }

    public static function hashIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        // Salted with the app key so hashes are useless outside this install.
        return hash('sha256', config('app.key').'|'.$ip);
    }

    public static function mintSessionKey(): string
    {
        return Str::random(40);
    }
}
