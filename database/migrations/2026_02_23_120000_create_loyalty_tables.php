<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── loyalty_cards ───────────────────────────────────────────
        if (Schema::hasTable('loyalty_cards')) {
            // Table exists — add any missing columns (no after() to avoid referencing missing columns)
            Schema::table('loyalty_cards', function (Blueprint $table) {
                if (!Schema::hasColumn('loyalty_cards', 'uuid')) {
                    $table->uuid('uuid')->unique()->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'slug')) {
                    $table->string('slug')->unique()->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'logo_url')) {
                    $table->string('logo_url', 500)->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'banner_url')) {
                    $table->string('banner_url', 500)->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'primary_color')) {
                    $table->string('primary_color', 7)->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'secondary_color')) {
                    $table->string('secondary_color', 7)->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'tiers')) {
                    $table->json('tiers')->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'status')) {
                    $table->string('status')->default('draft');
                }
                if (!Schema::hasColumn('loyalty_cards', 'published_at')) {
                    $table->timestamp('published_at')->nullable();
                }
                if (!Schema::hasColumn('loyalty_cards', 'total_members')) {
                    $table->unsignedInteger('total_members')->default(0);
                }
                if (!Schema::hasColumn('loyalty_cards', 'monthly_revenue')) {
                    $table->decimal('monthly_revenue', 12, 2)->default(0);
                }
                if (!Schema::hasColumn('loyalty_cards', 'allow_monthly')) {
                    $table->boolean('allow_monthly')->default(true);
                }
                if (!Schema::hasColumn('loyalty_cards', 'allow_yearly')) {
                    $table->boolean('allow_yearly')->default(true);
                }
                if (!Schema::hasColumn('loyalty_cards', 'auto_renew')) {
                    $table->boolean('auto_renew')->default(true);
                }
                if (!Schema::hasColumn('loyalty_cards', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        } else {
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();

            // Tier configuration stored as JSON
            $table->json('tiers');

            // Status
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();

            // Cached stats
            $table->unsignedInteger('total_members')->default(0);
            $table->decimal('monthly_revenue', 12, 2)->default(0);

            // Settings
            $table->boolean('allow_monthly')->default(true);
            $table->boolean('allow_yearly')->default(true);
            $table->boolean('auto_renew')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('artist_id');
            $table->index('status');
        });
        }

        // ─── loyalty_card_members ──────────────────────────────────
        if (!Schema::hasTable('loyalty_card_members')) {
        Schema::create('loyalty_card_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Tier
            $table->string('tier', 50);

            // Subscription
            $table->enum('subscription_type', ['monthly', 'yearly']);
            $table->decimal('price_paid', 12, 2);
            $table->string('currency', 3)->default('UGX');

            // Status
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])->default('pending');

            // Important dates
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('renewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Auto-renewal
            $table->boolean('auto_renew')->default(true);
            $table->boolean('renewal_reminder_sent')->default(false);

            // Payment
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_transaction_id')->nullable();

            // Stats
            $table->unsignedInteger('total_renewals')->default(0);
            $table->decimal('lifetime_value', 12, 2)->default(0);

            $table->timestamps();

            // A user can only have one active membership per card
            $table->unique(['loyalty_card_id', 'user_id', 'status'], 'unique_active_membership');
            $table->index('user_id');
            $table->index('status');
            $table->index('expires_at');
        });
        }

        // ─── loyalty_rewards ───────────────────────────────────────
        if (!Schema::hasTable('loyalty_rewards')) {
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_card_id')->constrained('loyalty_cards')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['content', 'merchandise', 'experience', 'discount', 'points']);

            // Eligibility
            $table->string('required_tier', 50);

            // Content rewards
            $table->string('content_type', 50)->nullable();
            $table->string('content_url', 500)->nullable();

            // Merchandise rewards
            $table->unsignedBigInteger('product_id')->nullable();
            if (Schema::hasTable('store_products')) {
                $table->foreign('product_id')->references('id')->on('store_products')->nullOnDelete();
            }
            $table->decimal('discount_percentage', 5, 2)->nullable();

            // Experience rewards
            $table->unsignedBigInteger('event_id')->nullable();
            if (Schema::hasTable('events')) {
                $table->foreign('event_id')->references('id')->on('events')->nullOnDelete();
            }
            $table->string('experience_type', 100)->nullable();

            // Points rewards
            $table->unsignedInteger('points_amount')->nullable();

            // Availability
            $table->boolean('is_active')->default(true);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('current_redemptions')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('loyalty_card_id');
            $table->index('type');
            $table->index('required_tier');
        });
        }

        // ─── loyalty_reward_redemptions ─────────────────────────────
        if (!Schema::hasTable('loyalty_reward_redemptions')) {
        Schema::create('loyalty_reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_reward_id')->constrained('loyalty_rewards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('loyalty_card_member_id')->constrained('loyalty_card_members')->cascadeOnDelete();

            $table->enum('status', ['pending', 'fulfilled', 'cancelled'])->default('pending');
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('fulfilment_notes')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });
        }

        // ─── loyalty_points ────────────────────────────────────────
        if (!Schema::hasTable('loyalty_points')) {
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->unsignedInteger('lifetime_spent')->default(0);
            $table->decimal('current_multiplier', 3, 2)->default(1.00);

            $table->timestamps();
        });
        }

        // ─── loyalty_transactions ──────────────────────────────────
        if (!Schema::hasTable('loyalty_transactions')) {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('type', ['earned', 'spent', 'expired', 'adjusted']);
            $table->integer('points'); // +ve earned, -ve spent
            $table->unsignedInteger('balance_after');

            // Polymorphic source
            $table->string('source'); // stream, download, purchase, event_attendance, referral, bonus, admin_adjustment, redemption
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->text('description')->nullable();

            // Multiplier
            $table->unsignedInteger('base_points')->nullable();
            $table->decimal('multiplier', 3, 2)->default(1.00);

            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('type');
            $table->index('source');
            $table->index('created_at');
        });
        }

        // ─── Add missing loyalty columns to event_tickets ──────────
        if (Schema::hasTable('event_tickets') && !Schema::hasColumn('event_tickets', 'required_loyalty_tier')) {
            Schema::table('event_tickets', function (Blueprint $table) {
                $table->string('required_loyalty_tier')->nullable();
                $table->unsignedInteger('tier_early_access_hours')->nullable();
                $table->json('tier_discounts')->nullable();
            });
        }

        // ─── Add hide_from_non_qualifying to events if missing ─────
        if (Schema::hasTable('events') && !Schema::hasColumn('events', 'hide_from_non_qualifying')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'loyalty_card_id')) {
                    $table->boolean('hide_from_non_qualifying')->default(false)->after('loyalty_card_id');
                } else {
                    $table->boolean('hide_from_non_qualifying')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_points');
        Schema::dropIfExists('loyalty_reward_redemptions');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_card_members');
        Schema::dropIfExists('loyalty_cards');

        if (Schema::hasTable('event_tickets')) {
            Schema::table('event_tickets', function (Blueprint $table) {
                $columns = array_filter(
                    ['required_loyalty_tier', 'tier_early_access_hours', 'tier_discounts'],
                    fn ($col) => Schema::hasColumn('event_tickets', $col)
                );
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'hide_from_non_qualifying')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('hide_from_non_qualifying');
            });
        }
    }
};
