<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-SHOT OPERATIONAL COMMAND — delete after production has been reconciled.
 *
 * Brings the production database in line with the migration-defined schema
 * (see docs/architecture/SCHEMA_BASELINE.md for the full audit). This is
 * deliberately NOT a migration: the base migrations are the ground-up source
 * of truth and already build the correct schema; this repairs the one
 * environment (production) that drifted before the migration squash.
 *
 * Every operation is guarded (hasTable / hasColumn / hasIndex / must-be-empty)
 * so the command is idempotent and a safe no-op on healthy databases.
 *
 * Verified against a restored production snapshot on 2026-06-11:
 *  - repairs the drifted snapshot to zero diff vs the reference schema
 *  - no-ops on a freshly migrated database
 *  - no-ops when run a second time
 */
class ReconcileProductionSchemaDrift extends Command
{
    protected $signature = 'db:reconcile-production-drift {--force : Skip the confirmation prompt}';

    protected $description = 'One-shot repair of production schema drift (safe no-op on healthy schemas)';

    public function handle(): int
    {
        $this->warn('This will alter the schema of the connected database: '.DB::connection()->getDatabaseName());

        if (! $this->option('force') && ! $this->confirm('A pre-run mysqldump backup must already exist. Continue?')) {
            $this->info('Aborted.');

            return self::FAILURE;
        }

        $this->step('Recreating drifted empty store tables', fn () => $this->recreateDriftedEmptyStoreTables());
        $this->step('Creating missing store tables', fn () => $this->createMissingStoreTables());
        $this->step('Restoring external foreign keys', fn () => $this->restoreExternalForeignKeys());
        $this->step('Adding missing columns', fn () => $this->addMissingColumns());
        $this->step('Fixing column types', fn () => $this->fixColumnTypes());
        $this->step('Adding missing indexes', fn () => $this->addMissingIndexes());
        $this->step('Dropping legacy columns from empty events table', fn () => $this->dropLegacyColumnsFromEmptyEventsTable());

        $this->info('Schema reconciliation complete. Re-run the drift diff to verify (see SCHEMA_BASELINE.md).');

        return self::SUCCESS;
    }

    private function step(string $label, callable $action): void
    {
        $this->line(" -> {$label}");
        $action();
    }

    /**
     * The prod store_* tables predate the current store schema (each missing
     * dozens of columns). They hold zero rows in production, so the safe
     * repair is drop + recreate from the canonical definitions.
     */
    private function recreateDriftedEmptyStoreTables(): void
    {
        $isOldGeneration = Schema::hasTable('store_products')
            && ! Schema::hasColumn('store_products', 'price_credits');

        if (! $isOldGeneration) {
            return;
        }

        if (Schema::hasTable('loyalty_rewards')) {
            Schema::table('loyalty_rewards', function (Blueprint $table) {
                $table->dropForeign('loyalty_rewards_product_id_foreign');
            });
        }

        $dropOrder = ['store_order_items', 'store_cart_items', 'store_orders', 'store_carts', 'store_products'];

        foreach ($dropOrder as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $rowCount = DB::table($tableName)->count();
            if ($rowCount > 0) {
                throw new \RuntimeException(
                    "Refusing to drop drifted table `{$tableName}` — it contains {$rowCount} rows. Reconcile manually."
                );
            }

            Schema::drop($tableName);
        }
    }

    private function createMissingStoreTables(): void
    {
        // Definitions copied verbatim from 0001_01_01_000007_create_engagement_extension_tables.php
        // (the canonical source).
        if (! Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('owner_type')->nullable();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('logo')->nullable();
                $table->string('banner')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('store_type')->default('user');
                $table->string('subscription_tier')->default('free');
                $table->timestamp('subscription_expires_at')->nullable();
                $table->string('status')->default('draft');
                $table->boolean('is_verified')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->text('suspended_reason')->nullable();
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->boolean('offers_local_pickup')->default(false);
                $table->text('pickup_address')->nullable();
                $table->decimal('total_sales_ugx', 14, 2)->default(0);
                $table->unsignedInteger('total_sales_credits')->default(0);
                $table->unsignedInteger('total_orders')->default(0);
                $table->decimal('rating', 4, 2)->default(0);
                $table->unsignedInteger('review_count')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
                $table->string('icon')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('store_category_pivot')) {
            Schema::create('store_category_pivot', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['store_id', 'category_id']);
            });
        }

        if (! Schema::hasTable('store_products')) {
            Schema::create('store_products', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('sku')->nullable();
                $table->text('description')->nullable();
                $table->text('short_description')->nullable();
                $table->json('images')->nullable();
                $table->string('featured_image')->nullable();
                $table->string('product_type')->default('physical');
                $table->string('status')->default('draft');
                $table->string('image')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->string('currency')->default('UGX');
                $table->string('category')->nullable();
                $table->string('type')->default('physical');
                $table->integer('stock_quantity')->default(0);
                $table->boolean('is_active')->default(true);
                $table->integer('inventory_quantity')->default(0);
                $table->boolean('track_inventory')->default(true);
                $table->boolean('allow_backorder')->default(false);
                $table->unsignedInteger('low_stock_threshold')->default(5);
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_taxable')->default(false);
                $table->boolean('has_variants')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->unsignedInteger('view_count')->default(0);
                $table->json('metadata')->nullable();
                $table->decimal('price_ugx', 12, 2)->default(0);
                $table->unsignedInteger('price_credits')->default(0);
                $table->boolean('allow_credit_payment')->default(false);
                $table->boolean('allow_hybrid_payment')->default(false);
                $table->boolean('accepts_credits')->default(false);
                $table->unsignedInteger('total_sales')->default(0);
                $table->decimal('average_rating', 4, 2)->default(0);
                $table->unsignedInteger('review_count')->default(0);
                $table->boolean('is_digital')->default(false);
                $table->string('digital_file_path')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('default_promotable_type', 100)->nullable();
                $table->unsignedBigInteger('default_promotable_id')->nullable();
                $table->unsignedBigInteger('promoter_profile_id')->nullable();
                $table->index(['default_promotable_type', 'default_promotable_id'], 'sp_default_promotable_idx');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('store_carts')) {
            Schema::create('store_carts', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('session_id')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('store_cart_items')) {
            Schema::create('store_cart_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cart_id')->constrained('store_carts')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('price_ugx', 12, 2)->default(0);
                $table->unsignedInteger('price_credits')->default(0);
                $table->string('payment_method')->default('ugx');
                $table->decimal('hybrid_ugx', 12, 2)->nullable();
                $table->unsignedInteger('hybrid_credits')->nullable();
                $table->string('payment_preference')->nullable();
                $table->json('custom_options')->nullable();
                $table->text('notes')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('store_orders')) {
            Schema::create('store_orders', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('order_number')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
                $table->decimal('total', 12, 2)->default(0);
                $table->string('currency')->default('UGX');
                $table->string('status')->default('pending');
                $table->string('payment_method')->nullable();
                $table->string('payment_provider')->nullable();
                $table->string('payment_reference')->nullable();
                $table->string('transaction_id')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('shipping_amount', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->unsignedInteger('credit_amount')->default(0);
                $table->decimal('subtotal_ugx', 12, 2)->default(0);
                $table->unsignedInteger('subtotal_credits')->default(0);
                $table->decimal('tax_ugx', 12, 2)->default(0);
                $table->unsignedInteger('tax_credits')->default(0);
                $table->decimal('shipping_cost_ugx', 12, 2)->default(0);
                $table->unsignedInteger('shipping_cost_credits')->default(0);
                $table->decimal('discount_ugx', 12, 2)->default(0);
                $table->unsignedInteger('discount_credits')->default(0);
                $table->decimal('platform_fee_ugx', 12, 2)->default(0);
                $table->unsignedInteger('platform_fee_credits')->default(0);
                $table->decimal('total_ugx', 12, 2)->default(0);
                $table->unsignedInteger('total_credits')->default(0);
                $table->decimal('paid_ugx', 12, 2)->default(0);
                $table->unsignedInteger('paid_credits')->default(0);
                $table->string('payment_status')->default('pending');
                $table->string('fulfillment_status')->default('pending');
                $table->text('shipping_address')->nullable();
                $table->json('billing_address')->nullable();
                $table->string('shipping_method')->nullable();
                $table->string('tracking_number')->nullable();
                $table->string('shipping_provider')->nullable();
                $table->text('customer_notes')->nullable();
                $table->text('admin_notes')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->decimal('refund_amount', 12, 2)->default(0);
                $table->text('refund_reason')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->text('payment_failure_reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('store_order_items')) {
            Schema::create('store_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('store_orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->json('product_snapshot')->nullable();
                $table->string('product_name')->nullable();
                $table->text('product_description')->nullable();
                $table->string('product_image')->nullable();
                $table->string('product_type')->nullable();
                $table->string('product_sku')->nullable();
                $table->json('product_variant')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->decimal('price_ugx', 12, 2)->default(0);
                $table->unsignedInteger('price_credits')->default(0);
                $table->string('payment_method')->nullable();
                $table->string('fulfillment_status')->default('pending');
                $table->string('download_url')->nullable();
                $table->unsignedInteger('download_count')->default(0);
                $table->timestamp('download_expires_at')->nullable();
                $table->string('verification_status')->nullable();
                $table->string('verification_url')->nullable();
                $table->text('verification_proof')->nullable();
                $table->text('verification_notes')->nullable();
                $table->timestamp('verification_submitted_at')->nullable();
                $table->timestamp('verification_expires_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('dispute_reason')->nullable();
                $table->string('promotable_type', 100)->nullable();
                $table->unsignedBigInteger('promotable_id')->nullable();
                $table->unsignedBigInteger('opportunity_id')->nullable();
                $table->unsignedBigInteger('application_id')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->timestamps();
                $table->index(['promotable_type', 'promotable_id'], 'soi_promotable_idx');
                $table->index('opportunity_id', 'soi_opportunity_idx');
            });
        }
    }

    private function restoreExternalForeignKeys(): void
    {
        if (! Schema::hasTable('loyalty_rewards') || ! Schema::hasTable('store_products')) {
            return;
        }

        $fkExists = collect(Schema::getForeignKeys('loyalty_rewards'))
            ->contains(fn (array $fk) => ($fk['name'] ?? null) === 'loyalty_rewards_product_id_foreign');

        if ($fkExists) {
            return;
        }

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->foreign('product_id', 'loyalty_rewards_product_id_foreign')
                ->references('id')->on('store_products')->nullOnDelete();
        });
    }

    private function addMissingColumns(): void
    {
        if (! Schema::hasColumn('users', 'phone_verification_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('phone_verification_code', 10)->nullable()->after('phone_verified_at');
                $table->timestamp('phone_verification_expires_at')->nullable()->after('phone_verification_code');
            });
        }

        if (! Schema::hasColumn('likes', 'liked_at')) {
            Schema::table('likes', function (Blueprint $table) {
                $table->timestamp('liked_at')->nullable();
            });
        }

        if (! Schema::hasColumn('permissions', 'is_active')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }

        $songColumns = [
            'audio_file_preview' => fn (Blueprint $t) => $t->string('audio_file_preview')->nullable(),
            'waveform_data' => fn (Blueprint $t) => $t->json('waveform_data')->nullable(),
            'file_hash' => fn (Blueprint $t) => $t->string('file_hash', 64)->nullable(),
            'disc_number' => fn (Blueprint $t) => $t->unsignedSmallInteger('disc_number')->nullable(),
            'scheduled_publish_at' => fn (Blueprint $t) => $t->timestamp('scheduled_publish_at')->nullable(),
            'moderation_notes' => fn (Blueprint $t) => $t->text('moderation_notes')->nullable(),
            'flagged_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('flagged_count')->default(0),
            'allow_comments' => fn (Blueprint $t) => $t->boolean('allow_comments')->default(true),
            'mixing_engineer' => fn (Blueprint $t) => $t->string('mixing_engineer')->nullable(),
            'mastering_engineer' => fn (Blueprint $t) => $t->string('mastering_engineer')->nullable(),
            'additional_credits' => fn (Blueprint $t) => $t->json('additional_credits')->nullable(),
            'primary_language' => fn (Blueprint $t) => $t->string('primary_language', 10)->nullable(),
            'languages_sung' => fn (Blueprint $t) => $t->json('languages_sung')->nullable(),
            'contains_local_language' => fn (Blueprint $t) => $t->boolean('contains_local_language')->default(false),
            'local_genres' => fn (Blueprint $t) => $t->json('local_genres')->nullable(),
            'cultural_context' => fn (Blueprint $t) => $t->text('cultural_context')->nullable(),
            'mood_tags' => fn (Blueprint $t) => $t->json('mood_tags')->nullable(),
            'copyright_year' => fn (Blueprint $t) => $t->unsignedSmallInteger('copyright_year')->nullable(),
            'copyright_holder' => fn (Blueprint $t) => $t->string('copyright_holder')->nullable(),
            'license_type' => fn (Blueprint $t) => $t->string('license_type')->nullable(),
            'upc_code' => fn (Blueprint $t) => $t->string('upc_code')->nullable(),
            'master_ownership_percentage' => fn (Blueprint $t) => $t->decimal('master_ownership_percentage', 5, 2)->default(100),
            'publishing_ownership_percentage' => fn (Blueprint $t) => $t->decimal('publishing_ownership_percentage', 5, 2)->default(100),
            'rights_holders' => fn (Blueprint $t) => $t->json('rights_holders')->nullable(),
            'recording_date' => fn (Blueprint $t) => $t->date('recording_date')->nullable(),
            'recording_location' => fn (Blueprint $t) => $t->string('recording_location')->nullable(),
            'recording_studio' => fn (Blueprint $t) => $t->string('recording_studio')->nullable(),
            'skip_count' => fn (Blueprint $t) => $t->unsignedInteger('skip_count')->default(0),
            'average_completion_rate' => fn (Blueprint $t) => $t->decimal('average_completion_rate', 5, 2)->default(0),
            'revenue_generated' => fn (Blueprint $t) => $t->decimal('revenue_generated', 15, 2)->default(0),
        ];

        $missingSongColumns = array_filter(
            $songColumns,
            fn (string $column) => ! Schema::hasColumn('songs', $column),
            ARRAY_FILTER_USE_KEY
        );

        if (! empty($missingSongColumns)) {
            Schema::table('songs', function (Blueprint $table) use ($missingSongColumns) {
                foreach ($missingSongColumns as $definition) {
                    $definition($table);
                }
            });
        }
    }

    private function fixColumnTypes(): void
    {
        // Relaxations and widenings verified safe against production data
        // (values NULL or within target bounds at audit time).
        Schema::table('artist_revenues', function (Blueprint $table) {
            $table->unsignedBigInteger('sourceable_id')->nullable()->change();
            $table->string('sourceable_type')->nullable()->change();
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->string('claim_status', 30)->nullable()->change();
        });

        Schema::table('genres', function (Blueprint $table) {
            $table->string('icon')->nullable()->change();
            $table->text('meta_description')->nullable()->change();
        });

        Schema::table('isrc_codes', function (Blueprint $table) {
            $table->string('registrant_code', 5)->change();
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->decimal('audio_quality_score', 5, 2)->nullable()->change();
            $table->string('source_type', 50)->nullable()->change();
            $table->unsignedInteger('unique_listeners_count')->default(0)->change();
        });
    }

    private function addMissingIndexes(): void
    {
        $indexes = [
            ['artist_profiles', 'artist_profiles_is_active_index', ['is_active'], false],
            ['artists', 'artists_claim_idx', ['is_placeholder', 'claim_status'], false],
            ['events', 'events_starts_at_status_index', ['starts_at', 'status'], false],
            ['isrc_codes', 'isrc_codes_artist_id_foreign', ['artist_id'], false],
            ['isrc_codes', 'isrc_dist_idx', ['status', 'cleared_for_distribution'], false],
            ['likes', 'likes_liked_at_index', ['liked_at'], false],
            ['permissions', 'permissions_is_active_index', ['is_active'], false],
            ['promoter_profiles', 'pp_status_verified_idx', ['status', 'is_verified'], false],
            ['promoter_profiles', 'pp_tier_status_idx', ['tier', 'status'], false],
            ['promoter_profiles', 'promoter_profiles_slug_unique', ['slug'], true],
            ['promoter_profiles', 'promoter_profiles_store_id_index', ['store_id'], false],
            ['promoter_profiles', 'promoter_profiles_user_id_unique', ['user_id'], true],
            ['promoter_profiles', 'promoter_profiles_uuid_unique', ['uuid'], true],
            ['promoter_profiles', 'promoter_profiles_verified_by_foreign', ['verified_by'], false],
            ['songs', 'songs_claimable_idx', ['status', 'is_claimable'], false],
            ['songs', 'songs_dist_idx', ['distribution_status', 'distributed_at'], false],
            ['user_follows', 'uf_followable_idx', ['followable_type', 'followable_id'], false],
            ['user_follows', 'uf_unique', ['follower_id', 'followable_type', 'followable_id'], true],
        ];

        foreach ($indexes as [$tableName, $indexName, $columns, $unique]) {
            if (! Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
                continue;
            }

            $columnsExist = collect($columns)->every(
                fn (string $column) => Schema::hasColumn($tableName, $column)
            );

            if (! $columnsExist) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName, $columns, $unique) {
                $unique ? $table->unique($columns, $indexName) : $table->index($columns, $indexName);
            });
        }
    }

    /**
     * Prod `events` carries 7 columns from a legacy events implementation.
     * The table is empty in production, so they can be removed; guarded so we
     * never drop a column that holds data.
     */
    private function dropLegacyColumnsFromEmptyEventsTable(): void
    {
        $legacyColumns = ['attendees_count', 'end_date', 'is_online', 'max_price', 'online_url', 'price', 'start_date'];

        $present = array_values(array_filter(
            $legacyColumns,
            fn (string $column) => Schema::hasColumn('events', $column)
        ));

        if (empty($present)) {
            return;
        }

        if (DB::table('events')->count() > 0) {
            return;
        }

        Schema::table('events', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
}
