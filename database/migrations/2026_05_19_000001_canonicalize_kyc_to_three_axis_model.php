<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonicalize verification to the three-axis KYC model.
 *
 * The baseline migrations now define the canonical schema:
 *   - users has kyc_status / kyc_submitted_at / kyc_verified_at / kyc_expires_at / kyc_rejection_reason
 *   - artists.verification_status has been removed (axis 2 lives in artists.status)
 *   - artist_profiles.verification_status / verification_documents have been removed
 *     (axis 1 lives in users.kyc_status; documents live in kyc_documents)
 *
 * This migration handles environments that PRE-DATE the canonical baseline:
 *   1. Add the new users.kyc_status columns if they don't yet exist.
 *   2. Normalize kyc_documents.status — rewrite the legacy typo 'active' → 'verified'.
 *   3. Backfill users.kyc_status from real evidence (kyc_documents + phone).
 *   4. Backfill users.kyc_verified_at / kyc_expires_at from latest verified doc.
 *   5. Normalize artists.status — rewrite legacy 'active' / 'verified' → 'approved'.
 *   6. Drop the legacy verification columns from artists and artist_profiles.
 *
 * Every step is idempotent so the migration is safe to run multiple times
 * and on environments where some of the work has already been done.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->ensureUsersKycColumns();
            $this->normalizeKycDocumentStatus();
            $this->backfillUsersKycStatus();
            $this->backfillUsersKycVerifiedAt();
            $this->normalizeArtistsStatus();
            $this->dropLegacyVerificationColumns();
            $this->verifyConsistency();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Re-create the dropped columns so the previous app revision can still boot.
            Schema::table('artists', function ($table) {
                if (! Schema::hasColumn('artists', 'verification_status')) {
                    $table->string('verification_status')->default('pending')->after('catalog_manager_user_id');
                }
            });

            Schema::table('artist_profiles', function ($table) {
                if (! Schema::hasColumn('artist_profiles', 'verification_status')) {
                    $table->string('verification_status')->default('pending')->after('nin_number');
                }
                if (! Schema::hasColumn('artist_profiles', 'verification_documents')) {
                    $table->json('verification_documents')->nullable()->after('verification_status');
                }
            });

            DB::statement("
                UPDATE artists SET verification_status = CASE
                    WHEN status = 'approved' THEN 'verified'
                    WHEN status = 'rejected' THEN 'rejected'
                    WHEN status = 'suspended' THEN 'suspended'
                    ELSE 'pending'
                END
            ");

            DB::statement("
                UPDATE artist_profiles ap
                INNER JOIN users u ON u.id = ap.user_id
                SET ap.verification_status = CASE
                    WHEN u.kyc_status = 'verified' THEN 'verified'
                    WHEN u.kyc_status = 'rejected' THEN 'rejected'
                    ELSE 'pending'
                END
            ");

            DB::statement("UPDATE artists SET status = 'active' WHERE status = 'approved'");
            DB::statement("UPDATE kyc_documents SET status = 'active' WHERE status = 'verified'");

            // The KYC columns on users are part of the canonical baseline; we don't
            // drop them on rollback (they're harmless on the previous revision).
        });
    }

    private function ensureUsersKycColumns(): void
    {
        Schema::table('users', function ($table) {
            if (! Schema::hasColumn('users', 'kyc_status')) {
                $table->string('kyc_status', 32)->default('none')->after('selfie_with_id_path');
                $table->index('kyc_status');
            }
            foreach (['kyc_submitted_at', 'kyc_verified_at', 'kyc_expires_at'] as $col) {
                if (! Schema::hasColumn('users', $col)) {
                    $table->timestamp($col)->nullable();
                }
            }
            if (! Schema::hasColumn('users', 'kyc_rejection_reason')) {
                $table->text('kyc_rejection_reason')->nullable();
            }
        });
    }

    private function normalizeKycDocumentStatus(): void
    {
        if (! Schema::hasTable('kyc_documents')) {
            return;
        }

        $rewritten = DB::table('kyc_documents')
            ->where('status', 'active')
            ->update(['status' => 'verified']);

        if ($rewritten > 0) {
            echo "  [kyc] rewrote {$rewritten} kyc_documents rows: 'active' → 'verified'\n";
        }
    }

    private function backfillUsersKycStatus(): void
    {
        $affected = DB::affectingStatement(<<<'SQL'
            UPDATE users u
            LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(DISTINCT CASE WHEN status = 'verified' THEN document_type END) AS verified_types,
                    SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_cnt,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt,
                    COUNT(*) AS total_cnt
                FROM kyc_documents
                GROUP BY user_id
            ) d ON d.user_id = u.id
            SET u.kyc_status = CASE
                WHEN d.verified_types >= 3                                    THEN 'verified'
                WHEN d.pending_cnt > 0                                        THEN 'pending_review'
                WHEN d.total_cnt > 0 AND d.total_cnt = d.rejected_cnt         THEN 'rejected'
                WHEN d.total_cnt > 0                                          THEN 'partial'
                WHEN u.phone_verified_at IS NOT NULL                          THEN 'partial'
                WHEN u.phone IS NOT NULL AND u.phone <> ''                    THEN 'partial'
                ELSE 'none'
            END
            WHERE u.kyc_status IS NULL OR u.kyc_status = 'none' OR u.kyc_status = '';
        SQL);

        if ($affected > 0) {
            echo "  [kyc] backfilled kyc_status on {$affected} users\n";
        }
    }

    private function backfillUsersKycVerifiedAt(): void
    {
        $affected = DB::affectingStatement(<<<'SQL'
            UPDATE users u
            INNER JOIN (
                SELECT user_id, MAX(verified_at) AS latest_verified_at
                FROM kyc_documents
                WHERE status = 'verified'
                GROUP BY user_id
            ) d ON d.user_id = u.id
            SET u.kyc_verified_at = d.latest_verified_at,
                u.kyc_expires_at  = DATE_ADD(d.latest_verified_at, INTERVAL 365 DAY)
            WHERE u.kyc_status = 'verified' AND u.kyc_verified_at IS NULL;
        SQL);

        if ($affected > 0) {
            echo "  [kyc] set kyc_verified_at on {$affected} verified users\n";
        }
    }

    private function normalizeArtistsStatus(): void
    {
        $rewrites = [
            'active' => 'approved',
            'verified' => 'approved',
        ];

        foreach ($rewrites as $from => $to) {
            $affected = DB::table('artists')->where('status', $from)->update(['status' => $to]);
            if ($affected > 0) {
                echo "  [artist.status] {$affected} rows: '{$from}' → '{$to}'\n";
            }
        }
    }

    private function dropLegacyVerificationColumns(): void
    {
        Schema::table('artists', function ($table) {
            if (Schema::hasColumn('artists', 'verification_status')) {
                $table->dropColumn('verification_status');
            }
        });

        Schema::table('artist_profiles', function ($table) {
            if (Schema::hasColumn('artist_profiles', 'verification_status')) {
                $table->dropColumn('verification_status');
            }
            if (Schema::hasColumn('artist_profiles', 'verification_documents')) {
                $table->dropColumn('verification_documents');
            }
        });
    }

    private function verifyConsistency(): void
    {
        $invalidKyc = DB::table('users')
            ->whereNotIn('kyc_status', ['none', 'partial', 'pending_review', 'verified', 'rejected', 'expired'])
            ->count();

        if ($invalidKyc > 0) {
            throw new RuntimeException(
                "kyc_status backfill produced {$invalidKyc} rows with invalid status values. Rolling back."
            );
        }

        $verifiedWithoutDocs = DB::table('users')
            ->where('kyc_status', 'verified')
            ->whereNotIn('id', fn ($q) => $q->select('user_id')->from('kyc_documents')->where('status', 'verified'))
            ->count();

        if ($verifiedWithoutDocs > 0) {
            throw new RuntimeException(
                "kyc_status backfill marked {$verifiedWithoutDocs} users as 'verified' without verified documents. Rolling back."
            );
        }

        $invalidArtist = DB::table('artists')
            ->whereNotIn('status', ['pending', 'approved', 'rejected', 'suspended'])
            ->count();

        if ($invalidArtist > 0) {
            throw new RuntimeException(
                "{$invalidArtist} rows still have non-canonical artists.status values. Rolling back."
            );
        }
    }
};
