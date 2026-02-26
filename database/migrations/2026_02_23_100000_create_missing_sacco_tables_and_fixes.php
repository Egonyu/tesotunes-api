<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates missing SACCO module tables, artist_profiles,
     * fixes table naming mismatches, and adds performance indexes.
     */
    public function up(): void
    {
        // ================================================================
        // 1. Fix table naming mismatches (model expects different name)
        // ================================================================

        // PlayHistory model expects 'play_histories' but base migration created 'play_history'
        if (Schema::hasTable('play_history') && ! Schema::hasTable('play_histories')) {
            Schema::rename('play_history', 'play_histories');
        }

        // Playlist pivot: models expect 'playlist_songs', base migration created 'playlist_song'
        if (Schema::hasTable('playlist_song') && ! Schema::hasTable('playlist_songs')) {
            Schema::rename('playlist_song', 'playlist_songs');
        }

        // ================================================================
        // 2. SACCO Module — Missing Tables
        // ================================================================

        // Savings Accounts
        if (! Schema::hasTable('sacco_savings_accounts')) {
            Schema::create('sacco_savings_accounts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('account_number')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('account_type')->default('regular'); // regular, fixed, junior
                $table->string('account_name')->nullable();
                $table->decimal('balance_ugx', 15, 2)->default(0);
                $table->decimal('interest_rate', 5, 2)->default(0);
                $table->decimal('accrued_interest_ugx', 15, 2)->default(0);
                $table->decimal('minimum_balance_ugx', 15, 2)->default(0);
                $table->decimal('withdrawal_limit_monthly', 15, 2)->nullable();
                $table->dateTime('maturity_date')->nullable();
                $table->boolean('allow_early_withdrawal')->default(true);
                $table->string('status')->default('active'); // active, frozen, closed
                $table->timestamps();

                $table->index('member_id');
                $table->index('status');
                $table->index('account_type');
            });
        }

        // Savings Transactions
        if (! Schema::hasTable('sacco_savings_transactions')) {
            Schema::create('sacco_savings_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('transaction_code')->unique();
                $table->foreignId('account_id')->constrained('sacco_savings_accounts')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('type'); // deposit, withdrawal, interest, fee
                $table->decimal('amount_ugx', 15, 2);
                $table->decimal('balance_before_ugx', 15, 2)->default(0);
                $table->decimal('balance_after_ugx', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->string('reference_number')->nullable();
                $table->string('status')->default('completed'); // pending, completed, failed, reversed
                $table->timestamps();

                $table->index(['account_id', 'created_at']);
                $table->index(['member_id', 'type']);
                $table->index('status');
            });
        }

        // Loan Repayments
        if (! Schema::hasTable('sacco_loan_repayments')) {
            Schema::create('sacco_loan_repayments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('payment_code')->unique();
                $table->foreignId('loan_id')->constrained('sacco_loans')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->decimal('amount_ugx', 15, 2);
                $table->decimal('principal_paid_ugx', 15, 2)->default(0);
                $table->decimal('interest_paid_ugx', 15, 2)->default(0);
                $table->decimal('penalty_paid_ugx', 15, 2)->default(0);
                $table->dateTime('payment_date');
                $table->date('due_date')->nullable();
                $table->boolean('is_early_payment')->default(false);
                $table->boolean('is_late_payment')->default(false);
                $table->string('payment_method')->nullable(); // mobile_money, bank, cash
                $table->string('reference_number')->nullable();
                $table->timestamps();

                $table->index(['loan_id', 'payment_date']);
                $table->index('member_id');
            });
        }

        // Loan Products (loan type configurations)
        if (! Schema::hasTable('sacco_loan_products')) {
            Schema::create('sacco_loan_products', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->decimal('min_amount', 15, 2)->default(0);
                $table->decimal('max_amount', 15, 2)->default(0);
                $table->decimal('interest_rate', 5, 2)->default(0);
                $table->integer('min_term_months')->default(1);
                $table->integer('max_term_months')->default(12);
                $table->decimal('processing_fee_percentage', 5, 2)->default(0);
                $table->decimal('insurance_fee_percentage', 5, 2)->default(0);
                $table->integer('min_guarantors')->default(0);
                $table->decimal('collateral_percentage', 5, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Shares
        if (! Schema::hasTable('sacco_shares')) {
            Schema::create('sacco_shares', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->integer('total_shares')->default(0);
                $table->decimal('share_value_ugx', 15, 2)->default(0);
                $table->decimal('total_value_ugx', 15, 2)->default(0);
                $table->dateTime('last_purchase_at')->nullable();
                $table->timestamps();

                $table->unique('member_id');
            });
        }

        // Share Transactions
        if (! Schema::hasTable('sacco_share_transactions')) {
            Schema::create('sacco_share_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('transaction_code')->unique();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->foreignId('share_id')->constrained('sacco_shares')->cascadeOnDelete();
                $table->string('type'); // purchase, transfer_in, transfer_out
                $table->integer('shares_quantity');
                $table->decimal('price_per_share_ugx', 15, 2);
                $table->decimal('total_amount_ugx', 15, 2);
                $table->string('status')->default('completed');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['member_id', 'created_at']);
            });
        }

        // Dividends
        if (! Schema::hasTable('sacco_dividends')) {
            Schema::create('sacco_dividends', function (Blueprint $table) {
                $table->id();
                $table->integer('dividend_year');
                $table->decimal('total_profit', 15, 2)->default(0);
                $table->decimal('dividend_rate', 5, 2)->default(0);
                $table->date('declaration_date')->nullable();
                $table->date('payment_date')->nullable();
                $table->string('status')->default('declared'); // declared, approved, paid
                $table->timestamps();

                $table->unique('dividend_year');
            });
        }

        // Member Dividends
        if (! Schema::hasTable('sacco_member_dividends')) {
            Schema::create('sacco_member_dividends', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dividend_id')->constrained('sacco_dividends')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->decimal('shares_amount', 15, 2)->default(0);
                $table->decimal('dividend_amount', 15, 2)->default(0);
                $table->string('status')->default('pending'); // pending, paid
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();

                $table->unique(['dividend_id', 'member_id']);
            });
        }

        // Settings
        if (! Schema::hasTable('sacco_settings')) {
            Schema::create('sacco_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('type')->default('string'); // string, integer, boolean, json
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        // Accounts (general ledger accounts)
        if (! Schema::hasTable('sacco_accounts')) {
            Schema::create('sacco_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('account_number')->unique();
                $table->string('account_type'); // savings, shares, loan
                $table->decimal('balance', 15, 2)->default(0);
                $table->decimal('available_balance', 15, 2)->default(0);
                $table->decimal('interest_rate', 5, 2)->default(0);
                $table->string('status')->default('active');
                $table->dateTime('opened_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->timestamps();

                $table->index('member_id');
                $table->index('status');
            });
        }

        // Audit Logs
        if (! Schema::hasTable('sacco_audit_logs')) {
            Schema::create('sacco_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action');
                $table->string('model_type')->nullable();
                $table->unsignedBigInteger('model_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['model_type', 'model_id']);
                $table->index('user_id');
                $table->index('action');
            });
        }

        // Board Members
        if (! Schema::hasTable('sacco_board_members')) {
            Schema::create('sacco_board_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
                $table->string('position'); // chairperson, secretary, treasurer, member
                $table->date('term_start_date')->nullable();
                $table->date('term_end_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('member_id');
                $table->index('is_active');
            });
        }

        // Board Meetings
        if (! Schema::hasTable('sacco_board_meetings')) {
            Schema::create('sacco_board_meetings', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('agenda')->nullable();
                $table->dateTime('scheduled_at');
                $table->string('venue')->nullable();
                $table->string('status')->default('scheduled'); // scheduled, in_progress, completed, cancelled
                $table->text('minutes')->nullable();
                $table->json('decisions')->nullable();
                $table->timestamps();

                $table->index('scheduled_at');
                $table->index('status');
            });
        }

        // Board Meeting Attendance
        if (! Schema::hasTable('sacco_board_meeting_attendance')) {
            Schema::create('sacco_board_meeting_attendance', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained('sacco_board_meetings')->cascadeOnDelete();
                $table->foreignId('board_member_id')->constrained('sacco_board_members')->cascadeOnDelete();
                $table->string('status')->default('pending'); // pending, present, absent, excused
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['meeting_id', 'board_member_id']);
            });
        }

        // ================================================================
        // 3. Artist Profiles
        // ================================================================

        if (! Schema::hasTable('artist_profiles')) {
            Schema::create('artist_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
                $table->string('stage_name')->nullable();
                $table->string('real_name')->nullable();
                $table->string('nin_number')->nullable();
                $table->string('verification_status')->default('unverified'); // unverified, pending, verified, rejected
                $table->json('verification_documents')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->text('bio')->nullable();
                $table->string('website')->nullable();
                $table->json('social_links')->nullable();
                $table->string('manager_name')->nullable();
                $table->string('manager_contact')->nullable();
                $table->json('genres')->nullable();
                $table->json('languages')->nullable();
                $table->string('record_label')->nullable();
                $table->string('publishing_company')->nullable();
                $table->string('region')->nullable();
                $table->string('district')->nullable();
                $table->string('career_stage')->nullable(); // emerging, established, veteran
                $table->string('mobile_money_provider')->nullable();
                $table->string('mobile_money_number')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('bank_account')->nullable();
                $table->string('payout_method')->default('mobile_money');
                $table->decimal('minimum_payout', 15, 2)->default(50000); // 50,000 UGX
                $table->decimal('total_credits_earned', 15, 2)->default(0);
                $table->decimal('total_money_earned', 15, 2)->default(0);
                $table->boolean('money_payout_enabled')->default(false);
                $table->dateTime('money_payout_unlocked_at')->nullable();
                $table->boolean('auto_distribute')->default(false);
                $table->json('distribution_preferences')->nullable();
                $table->decimal('distribution_fee_percentage', 5, 2)->default(15);
                $table->boolean('public_stats')->default(true);
                $table->boolean('detailed_analytics')->default(false);
                $table->boolean('is_active')->default(true);
                $table->dateTime('last_login_at')->nullable();
                $table->boolean('profile_completed')->default(false);
                $table->timestamps();

                $table->unique('user_id');
                $table->index('artist_id');
                $table->index('verification_status');
            });
        }

        // ================================================================
        // 4. Performance Indexes (M6)
        // ================================================================

        // Songs — most queried table
        if (Schema::hasTable('songs')) {
            Schema::table('songs', function (Blueprint $table) {
                if (! $this->hasIndex('songs', 'songs_status_index')) {
                    $table->index('status', 'songs_status_index');
                }
                if (! $this->hasIndex('songs', 'songs_artist_id_index')) {
                    $table->index('artist_id', 'songs_artist_id_index');
                }
                if (! $this->hasIndex('songs', 'songs_created_at_index')) {
                    $table->index('created_at', 'songs_created_at_index');
                }
                if (! $this->hasIndex('songs', 'songs_genre_id_index') && Schema::hasColumn('songs', 'genre_id')) {
                    $table->index('genre_id', 'songs_genre_id_index');
                }
            });
        }

        // Users — frequently filtered
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! $this->hasIndex('users', 'users_role_index') && Schema::hasColumn('users', 'role')) {
                    $table->index('role', 'users_role_index');
                }
            });
        }

        // Play histories — analytics queries
        if (Schema::hasTable('play_histories')) {
            Schema::table('play_histories', function (Blueprint $table) {
                if (! $this->hasIndex('play_histories', 'play_histories_user_song_index')) {
                    $table->index(['user_id', 'song_id'], 'play_histories_user_song_index');
                }
            });
        }

        // Likes — frequently queried
        if (Schema::hasTable('likes')) {
            Schema::table('likes', function (Blueprint $table) {
                if (! $this->hasIndex('likes', 'likes_user_likeable_index')) {
                    $table->index(['user_id', 'likeable_type', 'likeable_id'], 'likes_user_likeable_index');
                }
            });
        }

        // Payments — financial queries
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! $this->hasIndex('payments', 'payments_status_index')) {
                    $table->index('status', 'payments_status_index');
                }
                if (! $this->hasIndex('payments', 'payments_user_id_index')) {
                    $table->index('user_id', 'payments_user_id_index');
                }
            });
        }
    }

    /**
     * Check if an index exists on a table.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $result = Schema::getConnection()->select(
                "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
                [$indexName]
            );
            return count($result) > 0;
        } catch (\Exception) {
            return false;
        }
    }

    public function down(): void
    {
        // Drop SACCO tables in reverse dependency order
        Schema::dropIfExists('sacco_board_meeting_attendance');
        Schema::dropIfExists('sacco_board_meetings');
        Schema::dropIfExists('sacco_board_members');
        Schema::dropIfExists('sacco_audit_logs');
        Schema::dropIfExists('sacco_accounts');
        Schema::dropIfExists('sacco_settings');
        Schema::dropIfExists('sacco_member_dividends');
        Schema::dropIfExists('sacco_dividends');
        Schema::dropIfExists('sacco_share_transactions');
        Schema::dropIfExists('sacco_shares');
        Schema::dropIfExists('sacco_loan_products');
        Schema::dropIfExists('sacco_loan_repayments');
        Schema::dropIfExists('sacco_savings_transactions');
        Schema::dropIfExists('sacco_savings_accounts');
        Schema::dropIfExists('artist_profiles');

        // Reverse table renames
        if (Schema::hasTable('play_histories') && ! Schema::hasTable('play_history')) {
            Schema::rename('play_histories', 'play_history');
        }
        if (Schema::hasTable('playlist_songs') && ! Schema::hasTable('playlist_song')) {
            Schema::rename('playlist_songs', 'playlist_song');
        }
    }
};
