<?php

namespace App\Services\Kyc;

use App\Enums\KycDocumentStatus;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\AuditLog;
use App\Models\KYCDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * KycService is the SINGLE writer to users.kyc_status.
 *
 * No other class should mutate users.kyc_status directly — all transitions
 * go through this service so the lifecycle stays consistent, auditable,
 * and accompanied by side effects (audit log entry, document status sync,
 * notification dispatch, etc.).
 */
class KycService
{
    public const ACTION_WITHDRAWAL = 'withdrawal';

    public const ACTION_MUSIC_CLAIM = 'music_claim';

    public const ACTION_DISPUTE = 'dispute';

    public const ACTION_PAYOUT_METHOD_CHANGE = 'payout_method_change';

    /**
     * Default re-verification window. Verified users must re-confirm
     * their identity annually. The scheduled job flips users past this
     * window to KycStatus::Expired.
     */
    public const VERIFICATION_TTL_DAYS = 365;

    /**
     * Compute what the user's kyc_status SHOULD be based on the
     * underlying evidence (kyc_documents + phone verification).
     *
     * Used by the preflight report and the backfill migration.
     * Does NOT write — caller decides whether to persist.
     */
    public function computeStatus(User $user): KycStatus
    {
        $requiredTypes = KycDocumentType::required();
        $documents = $user->kycDocuments()->get();

        if ($documents->isEmpty()) {
            return $user->phone_verified_at !== null
                ? KycStatus::Partial
                : KycStatus::None;
        }

        $verifiedTypes = $documents
            ->filter(fn ($d) => $d->status === KycDocumentStatus::Verified)
            ->map(fn ($d) => $d->document_type instanceof KycDocumentType
                ? $d->document_type
                : KycDocumentType::tryFrom((string) $d->document_type))
            ->filter()
            ->unique()
            ->values();

        $hasAllVerified = collect($requiredTypes)->every(
            fn (KycDocumentType $type) => $verifiedTypes->contains($type)
        );

        if ($hasAllVerified) {
            return KycStatus::Verified;
        }

        if ($documents->contains(fn ($d) => $d->status === KycDocumentStatus::Pending)) {
            return KycStatus::PendingReview;
        }

        if ($documents->every(fn ($d) => $d->status === KycDocumentStatus::Rejected)) {
            return KycStatus::Rejected;
        }

        return KycStatus::Partial;
    }

    /**
     * Submit a single KYC document. Creates the kyc_documents row,
     * transitions user to PendingReview if appropriate.
     */
    public function submitDocument(
        User $user,
        KycDocumentType $type,
        UploadedFile $file,
        ?string $documentNumber = null,
    ): KYCDocument {
        $current = $this->currentStatus($user);

        if (! $current->canSubmitDocuments() && $current !== KycStatus::PendingReview) {
            throw new RuntimeException(
                "Cannot submit documents while KYC status is '{$current->value}'."
            );
        }

        return DB::transaction(function () use ($user, $type, $file, $documentNumber) {
            $path = $file->store("kyc/{$user->id}", 'private');

            $document = KYCDocument::create([
                'user_id' => $user->id,
                'document_type' => $type,
                'document_number' => $documentNumber,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => KycDocumentStatus::Pending,
                'ip_address' => request()?->ip(),
            ]);

            $this->refreshStatusFromEvidence($user, actorId: $user->id, reason: 'document_submitted');

            return $document;
        });
    }

    /**
     * Admin action: approve a user's KYC submission.
     *
     * Marks all pending documents verified and flips the user to
     * KycStatus::Verified with a TTL on kyc_expires_at.
     */
    public function markVerified(User $user, User $admin, ?string $notes = null): void
    {
        $this->ensureAdmin($admin);

        DB::transaction(function () use ($user, $admin, $notes) {
            KYCDocument::query()
                ->where('user_id', $user->id)
                ->where('status', KycDocumentStatus::Pending)
                ->update([
                    'status' => KycDocumentStatus::Verified->value,
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                ]);

            $this->transition(
                user: $user,
                to: KycStatus::Verified,
                actorId: $admin->id,
                reason: 'admin_approved',
                extra: ['notes' => $notes],
            );

            $user->forceFill([
                'kyc_verified_at' => now(),
                'kyc_expires_at' => now()->addDays(self::VERIFICATION_TTL_DAYS),
                'kyc_rejection_reason' => null,
            ])->save();
        });
    }

    /**
     * Admin action: reject the user's KYC submission with a reason.
     */
    public function markRejected(User $user, User $admin, string $reason): void
    {
        $this->ensureAdmin($admin);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Rejection reason cannot be empty.');
        }

        DB::transaction(function () use ($user, $admin, $reason) {
            KYCDocument::query()
                ->where('user_id', $user->id)
                ->where('status', KycDocumentStatus::Pending)
                ->update([
                    'status' => KycDocumentStatus::Rejected->value,
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                    'rejection_reason' => $reason,
                ]);

            $this->transition(
                user: $user,
                to: KycStatus::Rejected,
                actorId: $admin->id,
                reason: 'admin_rejected',
                extra: ['reason' => $reason],
            );

            $user->forceFill([
                'kyc_rejection_reason' => $reason,
                'kyc_verified_at' => null,
                'kyc_expires_at' => null,
            ])->save();
        });
    }

    /**
     * Move a verified user past TTL into the Expired state.
     * Used by the scheduled re-verification job.
     */
    public function markExpired(User $user): void
    {
        if ($this->currentStatus($user) !== KycStatus::Verified) {
            return;
        }

        $this->transition(
            user: $user,
            to: KycStatus::Expired,
            actorId: null,
            reason: 'ttl_expired',
        );
    }

    /**
     * Recompute and persist user.kyc_status from current evidence.
     * Idempotent — safe to call repeatedly.
     */
    public function refreshStatusFromEvidence(
        User $user,
        ?int $actorId = null,
        string $reason = 'recomputed',
    ): KycStatus {
        $computed = $this->computeStatus($user);
        $current = $this->currentStatus($user);

        if ($computed !== $current) {
            $this->transition($user, $computed, $actorId, $reason);
        }

        return $computed;
    }

    /**
     * Whether the user can perform the given sensitive action.
     */
    public function eligibleFor(User $user, string $action): bool
    {
        return empty($this->missingStepsFor($user, $action));
    }

    /**
     * What steps the user is missing to perform the given action.
     * Returned as a list of stable string identifiers that the
     * frontend uses to render the appropriate stepper UI.
     *
     * @return list<string>
     */
    public function missingStepsFor(User $user, string $action): array
    {
        $missing = [];

        if (! $this->currentStatus($user)->isEligibleForSensitiveActions()) {
            $missing[] = 'kyc_verified';
        }

        if (! $this->phoneIsAcceptable($user)) {
            $missing[] = 'phone_verified';
        }

        if (in_array($action, [self::ACTION_WITHDRAWAL, self::ACTION_PAYOUT_METHOD_CHANGE], true)) {
            $profile = $user->artistProfile;
            $hasPayoutMethod = $profile
                && ($profile->mobile_money_number || $profile->bank_account);

            if (! $hasPayoutMethod) {
                $missing[] = 'payout_method';
            }
        }

        return $missing;
    }

    /**
     * Whether the user's phone meets the current verification policy.
     *
     * SMS verification is not yet implemented on the platform. Until it is,
     * the policy treats phone PRESENCE as sufficient. Once SMS rolls out,
     * flip config('kyc.require_phone_verification') to true and a re-eval
     * command will downgrade users whose phone has never been SMS-verified.
     */
    private function phoneIsAcceptable(User $user): bool
    {
        if (config('kyc.require_phone_verification', false)) {
            return $user->phone_verified_at !== null;
        }

        return ! empty($user->phone);
    }

    /**
     * Structured payload returned by middleware / API when a user
     * tries to access a gated action without meeting requirements.
     *
     * @return array{error: string, action: string, missing_steps: list<string>, redirect: string}
     */
    public function rejectionPayload(User $user, string $action): array
    {
        return [
            'error' => 'kyc_required',
            'action' => $action,
            'missing_steps' => $this->missingStepsFor($user, $action),
            'redirect' => '/account/verify-identity',
        ];
    }

    public function currentStatus(User $user): KycStatus
    {
        $raw = $user->kyc_status;

        if ($raw instanceof KycStatus) {
            return $raw;
        }

        return KycStatus::tryFrom((string) $raw) ?? KycStatus::None;
    }

    public function expirationFor(User $user): ?Carbon
    {
        return $user->kyc_expires_at;
    }

    /**
     * Persist a status change with audit logging.
     *
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        User $user,
        KycStatus $to,
        ?int $actorId,
        string $reason,
        array $extra = [],
    ): void {
        $from = $this->currentStatus($user);

        $updates = ['kyc_status' => $to->value];

        if ($to === KycStatus::PendingReview && $user->kyc_submitted_at === null) {
            $updates['kyc_submitted_at'] = now();
        }

        $user->forceFill($updates)->save();

        AuditLog::create([
            'user_id' => $actorId ?? $user->id,
            'action' => 'kyc.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['kyc_status' => $from->value],
            'new_values' => array_merge(
                ['kyc_status' => $to->value, 'reason' => $reason],
                $extra,
            ),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        Log::info('kyc.status_changed', [
            'user_id' => $user->id,
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
            'actor_id' => $actorId,
        ]);
    }

    private function ensureAdmin(User $admin): void
    {
        if (! $admin->hasAnyRole(['admin', 'super_admin', 'moderator'])) {
            throw new RuntimeException('Only admins can review KYC submissions.');
        }
    }
}
