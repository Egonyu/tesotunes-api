<?php

namespace App\Console\Commands\Kyc;

use App\Enums\KycStatus;
use App\Models\User;
use App\Services\Kyc\KycService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports what the KYC normalization migration WILL DO before running it.
 *
 * Run against the live database to:
 *   - Confirm 'active' → 'verified' rewrite size in kyc_documents
 *   - Preview kyc_status backfill distribution
 *   - Surface anomalies (e.g. users with is_verified=true but no docs)
 *
 * If the report looks sane, run the migration. If not, abort and investigate.
 *
 * Usage:
 *   php artisan kyc:verify-preflight
 *   php artisan kyc:verify-preflight --sample=10
 */
class VerifyPreflightCommand extends Command
{
    protected $signature = 'kyc:verify-preflight
                            {--sample=5 : Number of sample user rows per status bucket to show}';

    protected $description = 'Preview the KYC normalization migration — counts, transformations, anomalies.';

    public function handle(KycService $kyc): int
    {
        $this->info('===== KYC Normalization Preflight =====');
        $this->newLine();

        $this->reportKycDocumentStatusDistribution();
        $this->newLine();

        $this->reportComputedKycStatusDistribution($kyc);
        $this->newLine();

        $this->reportLegacyArtistVerificationStatus();
        $this->newLine();

        $this->reportAnomalies();
        $this->newLine();

        $this->reportSamples($kyc, (int) $this->option('sample'));
        $this->newLine();

        $this->info('===== Preflight complete =====');
        $this->line('If counts look right, run: php artisan migrate');

        return self::SUCCESS;
    }

    private function reportKycDocumentStatusDistribution(): void
    {
        $this->line('<comment>1. kyc_documents.status distribution (BEFORE rewrite)</comment>');

        $rows = DB::table('kyc_documents')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  (no kyc_documents rows yet)');

            return;
        }

        $this->table(['status', 'count'], $rows->map(fn ($r) => (array) $r));

        $legacyActive = (int) DB::table('kyc_documents')->where('status', 'active')->count();
        if ($legacyActive > 0) {
            $this->warn("  → {$legacyActive} rows with status='active' will be rewritten to 'verified'.");
        }
    }

    private function reportComputedKycStatusDistribution(KycService $kyc): void
    {
        $this->line('<comment>2. Computed users.kyc_status (PREVIEW of backfill result)</comment>');

        $buckets = collect(KycStatus::cases())
            ->mapWithKeys(fn (KycStatus $s) => [$s->value => 0])
            ->all();

        User::query()
            ->select(['id', 'phone_verified_at'])
            ->chunkById(500, function ($users) use ($kyc, &$buckets) {
                foreach ($users as $user) {
                    $status = $kyc->computeStatus($user);
                    $buckets[$status->value]++;
                }
            });

        $rows = collect($buckets)->map(fn ($count, $status) => [
            'kyc_status' => $status,
            'count' => $count,
        ])->values()->all();

        $this->table(['kyc_status', 'count'], $rows);
    }

    private function reportLegacyArtistVerificationStatus(): void
    {
        $this->line('<comment>3. Legacy artists.verification_status distribution (will be dropped)</comment>');

        if (! \Schema::hasColumn('artists', 'verification_status')) {
            $this->line('  (column already dropped)');

            return;
        }

        $rows = DB::table('artists')
            ->selectRaw('verification_status, COUNT(*) as count')
            ->groupBy('verification_status')
            ->orderByDesc('count')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  (no artists yet)');

            return;
        }

        $this->table(['verification_status', 'count'], $rows->map(fn ($r) => (array) $r));
    }

    private function reportAnomalies(): void
    {
        $this->line('<comment>4. Anomalies to review</comment>');

        $usersIsVerifiedButNoDocs = DB::table('users')
            ->where('is_verified', true)
            ->whereNotIn('id', fn ($q) => $q->select('user_id')->from('kyc_documents'))
            ->count();

        $this->line("  users.is_verified=true but ZERO kyc_documents rows: <fg=yellow>{$usersIsVerifiedButNoDocs}</>");
        if ($usersIsVerifiedButNoDocs > 0) {
            $this->warn('   ↳ these users will get kyc_status=none (or partial if phone_verified). is_verified likely meant social-proof, not KYC. Confirm OK.');
        }

        $usersWithMixedDocStates = DB::table('kyc_documents')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT status) > 1')
            ->count();

        $this->line("  users with mixed-state KYC documents: <fg=yellow>{$usersWithMixedDocStates}</>");

        $orphanDocs = DB::table('kyc_documents')
            ->whereNotIn('user_id', fn ($q) => $q->select('id')->from('users'))
            ->count();

        if ($orphanDocs > 0) {
            $this->error("  ORPHAN kyc_documents rows (user no longer exists): {$orphanDocs}");
        } else {
            $this->line('  orphan kyc_documents rows: <fg=green>0</>');
        }

        $invalidDocTypes = DB::table('kyc_documents')
            ->whereNotIn('document_type', \App\Enums\KycDocumentType::values())
            ->count();

        if ($invalidDocTypes > 0) {
            $this->error("  kyc_documents rows with UNKNOWN document_type values: {$invalidDocTypes}");
        } else {
            $this->line('  unknown document_type values: <fg=green>0</>');
        }
    }

    private function reportSamples(KycService $kyc, int $perBucket): void
    {
        $this->line("<comment>5. Sample users per computed bucket (up to {$perBucket})</comment>");

        foreach (KycStatus::cases() as $status) {
            $samples = User::query()
                ->limit(50)
                ->get(['id', 'email', 'phone_verified_at'])
                ->filter(fn ($u) => $kyc->computeStatus($u) === $status)
                ->take($perBucket);

            if ($samples->isEmpty()) {
                continue;
            }

            $this->line("  <fg=cyan>[{$status->value}]</>");
            foreach ($samples as $u) {
                $this->line("    #{$u->id}  {$u->email}");
            }
        }
    }
}
