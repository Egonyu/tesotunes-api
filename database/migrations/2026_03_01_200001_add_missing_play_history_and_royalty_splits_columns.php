<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing columns to play_histories and royalty_splits tables
     * so they match their respective Eloquent model $fillable arrays.
     *
     * Addresses DATABASE_HEALTH_REPORT items #11 and #12 (HIGH).
     */
    public function up(): void
    {
        // ── play_histories ──────────────────────────────────────────────
        // Actual:  id, user_id, song_id, duration_played, completed, source,
        //          device_type, created_at, updated_at, duration_listened,
        //          playlist_id, ip_address, country
        // Missing: artist_id, album_id, played_at, duration_played_seconds (alias),
        //          skipped, completion_percentage, quality, city
        if (Schema::hasTable('play_histories')) {
            Schema::table('play_histories', function (Blueprint $table) {
                if (! Schema::hasColumn('play_histories', 'artist_id')) {
                    $table->unsignedBigInteger('artist_id')->nullable()->after('song_id');
                }
                if (! Schema::hasColumn('play_histories', 'album_id')) {
                    $table->unsignedBigInteger('album_id')->nullable()->after('artist_id');
                }
                if (! Schema::hasColumn('play_histories', 'played_at')) {
                    $table->timestamp('played_at')->nullable()->after('album_id');
                }
                if (! Schema::hasColumn('play_histories', 'duration_played_seconds')) {
                    $table->integer('duration_played_seconds')->nullable()->after('played_at');
                }
                if (! Schema::hasColumn('play_histories', 'skipped')) {
                    $table->boolean('skipped')->default(false)->after('completed');
                }
                if (! Schema::hasColumn('play_histories', 'completion_percentage')) {
                    $table->decimal('completion_percentage', 5, 2)->nullable()->after('skipped');
                }
                if (! Schema::hasColumn('play_histories', 'quality')) {
                    $table->string('quality', 10)->nullable()->after('device_type');
                }
                if (! Schema::hasColumn('play_histories', 'city')) {
                    $table->string('city', 100)->nullable()->after('country');
                }
            });

            // Back-fill played_at from created_at where null
            \DB::statement('UPDATE play_histories SET played_at = created_at WHERE played_at IS NULL AND created_at IS NOT NULL');
        }

        // ── royalty_splits ──────────────────────────────────────────────
        // Actual:  id, song_id, recipient_id, percentage, applies_to_streaming,
        //          applies_to_downloads, status, created_at, updated_at, uuid,
        //          recipient_name, recipient_email, role, applies_to_sync,
        //          total_paid, last_payment_date
        // Missing: Most of the model's $fillable fields
        if (Schema::hasTable('royalty_splits')) {
            Schema::table('royalty_splits', function (Blueprint $table) {
                $columns = [
                    'collaborator_name' => fn () => $table->string('collaborator_name')->nullable(),
                    'collaborator_email' => fn () => $table->string('collaborator_email')->nullable(),
                    'role_description' => fn () => $table->string('role_description')->nullable(),
                    'split_percentage' => fn () => $table->decimal('split_percentage', 5, 2)->nullable(),
                    'split_type' => fn () => $table->string('split_type', 30)->default('percentage'),
                    'fixed_amount' => fn () => $table->decimal('fixed_amount', 15, 2)->nullable(),
                    'payment_method' => fn () => $table->string('payment_method', 50)->nullable(),
                    'payment_details' => fn () => $table->text('payment_details')->nullable(),
                    'is_verified' => fn () => $table->boolean('is_verified')->default(false),
                    'has_agreed' => fn () => $table->boolean('has_agreed')->default(false),
                    'agreed_at' => fn () => $table->timestamp('agreed_at')->nullable(),
                    'agreement_signature' => fn () => $table->text('agreement_signature')->nullable(),
                    'notes' => fn () => $table->text('notes')->nullable(),
                    'recipient_role' => fn () => $table->string('recipient_role', 50)->nullable(),
                    'recipient_phone' => fn () => $table->string('recipient_phone', 30)->nullable(),
                    'recipient_payout_info' => fn () => $table->json('recipient_payout_info')->nullable(),
                    'recipient_status' => fn () => $table->string('recipient_status', 20)->default('pending'),
                    'applies_to_physical' => fn () => $table->boolean('applies_to_physical')->default(true),
                    'applies_to_performance' => fn () => $table->boolean('applies_to_performance')->default(true),
                    'applies_to_mechanical' => fn () => $table->boolean('applies_to_mechanical')->default(true),
                    'territorial_scope' => fn () => $table->json('territorial_scope')->nullable(),
                    'worldwide' => fn () => $table->boolean('worldwide')->default(true),
                    'effective_from' => fn () => $table->date('effective_from')->nullable(),
                    'effective_until' => fn () => $table->date('effective_until')->nullable(),
                    'minimum_plays' => fn () => $table->integer('minimum_plays')->default(0),
                    'minimum_revenue' => fn () => $table->decimal('minimum_revenue', 15, 2)->default(0),
                    'agreement_reference' => fn () => $table->string('agreement_reference')->nullable(),
                    'agreement_type' => fn () => $table->string('agreement_type', 30)->nullable(),
                    'tax_withholding_required' => fn () => $table->boolean('tax_withholding_required')->default(false),
                    'tax_withholding_rate' => fn () => $table->decimal('tax_withholding_rate', 5, 2)->nullable(),
                    'tax_form_type' => fn () => $table->string('tax_form_type', 30)->nullable(),
                    'payout_frequency' => fn () => $table->string('payout_frequency', 20)->default('monthly'),
                    'minimum_payout_amount' => fn () => $table->decimal('minimum_payout_amount', 15, 2)->default(50000),
                    'auto_payout_enabled' => fn () => $table->boolean('auto_payout_enabled')->default(false),
                    'last_payout_at' => fn () => $table->timestamp('last_payout_at')->nullable(),
                    'total_paid_out' => fn () => $table->decimal('total_paid_out', 15, 2)->default(0),
                    'pending_payout' => fn () => $table->decimal('pending_payout', 15, 2)->default(0),
                    'approved_at' => fn () => $table->timestamp('approved_at')->nullable(),
                    'approved_by' => fn () => $table->unsignedBigInteger('approved_by')->nullable(),
                ];

                foreach ($columns as $name => $definition) {
                    if (! Schema::hasColumn('royalty_splits', $name)) {
                        $definition();
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('play_histories')) {
            Schema::table('play_histories', function (Blueprint $table) {
                $cols = ['artist_id', 'album_id', 'played_at', 'duration_played_seconds', 'skipped', 'completion_percentage', 'quality', 'city'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('play_histories', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('royalty_splits')) {
            Schema::table('royalty_splits', function (Blueprint $table) {
                $cols = [
                    'collaborator_name', 'collaborator_email', 'role_description',
                    'split_percentage', 'split_type', 'fixed_amount', 'payment_method',
                    'payment_details', 'is_verified', 'has_agreed', 'agreed_at',
                    'agreement_signature', 'notes', 'recipient_role', 'recipient_phone',
                    'recipient_payout_info', 'recipient_status', 'applies_to_physical',
                    'applies_to_performance', 'applies_to_mechanical', 'territorial_scope',
                    'worldwide', 'effective_from', 'effective_until', 'minimum_plays',
                    'minimum_revenue', 'agreement_reference', 'agreement_type',
                    'tax_withholding_required', 'tax_withholding_rate', 'tax_form_type',
                    'payout_frequency', 'minimum_payout_amount', 'auto_payout_enabled',
                    'last_payout_at', 'total_paid_out', 'pending_payout', 'approved_at',
                    'approved_by',
                ];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('royalty_splits', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
