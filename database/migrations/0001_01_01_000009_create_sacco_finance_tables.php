<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('member_number')->unique();
            $table->string('status')->default('active');
            $table->string('member_type')->default('regular');
            $table->string('membership_type')->default('regular');
            $table->string('membership_tier')->default('basic');
            $table->timestamp('joined_at')->nullable();
            $table->date('joined_date')->nullable();
            $table->timestamp('approval_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('loan_access_enabled')->default(false);
            $table->timestamp('loan_eligible_at')->nullable();
            $table->string('id_number')->nullable();
            $table->string('id_type')->nullable();
            $table->string('national_id')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();
            $table->decimal('monthly_income', 15, 2)->nullable();
            $table->unsignedInteger('credit_score')->default(400);
            $table->boolean('kyc_verified')->default(false);
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();
            $table->decimal('total_shares', 15, 2)->default(0);
            $table->decimal('total_savings', 15, 2)->default(0);
            $table->decimal('total_loans', 15, 2)->default(0);
            $table->boolean('auto_deposit_enabled')->default(false);
            $table->decimal('auto_deposit_percentage', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'member_type']);
            $table->index(['membership_tier', 'loan_access_enabled']);
            $table->index(['credit_score', 'kyc_verified']);
        });

        Schema::create('sacco_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('account_number')->unique();
            $table->string('account_type');
            $table->string('account_name')->nullable();
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('available_balance', 15, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('interest_earned', 15, 2)->default(0);
            $table->date('last_interest_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'account_type']);
            $table->index(['status', 'account_type']);
        });

        Schema::create('sacco_savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('account_number')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('account_type')->default('regular');
            $table->string('account_name')->nullable();
            $table->decimal('balance_ugx', 15, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('accrued_interest_ugx', 15, 2)->default(0);
            $table->decimal('minimum_balance_ugx', 15, 2)->default(0);
            $table->decimal('withdrawal_limit_monthly', 15, 2)->nullable();
            $table->timestamp('maturity_date')->nullable();
            $table->boolean('allow_early_withdrawal')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['member_id', 'account_type']);
            $table->index(['status', 'account_type']);
        });

        Schema::create('sacco_savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('transaction_code')->unique();
            $table->foreignId('account_id')->constrained('sacco_savings_accounts')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount_ugx', 15, 2);
            $table->decimal('balance_before_ugx', 15, 2)->default(0);
            $table->decimal('balance_after_ugx', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['member_id', 'type']);
            $table->index('status');
        });

        Schema::create('sacco_loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('loan_type')->nullable();
            $table->decimal('min_amount', 15, 2)->default(0);
            $table->decimal('max_amount', 15, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('default_interest_rate', 5, 2)->default(0);
            $table->integer('min_term_months')->default(1);
            $table->integer('max_term_months')->default(12);
            $table->integer('min_repayment_months')->default(1);
            $table->integer('max_repayment_months')->default(12);
            $table->decimal('processing_fee_percentage', 5, 2)->default(0);
            $table->decimal('insurance_fee_percentage', 5, 2)->default(0);
            $table->boolean('requires_guarantor')->default(false);
            $table->integer('min_guarantors')->default(0);
            $table->boolean('requires_collateral')->default(false);
            $table->decimal('collateral_percentage', 5, 2)->default(0);
            $table->decimal('min_savings_balance_required', 15, 2)->default(0);
            $table->decimal('max_loan_to_savings_ratio', 5, 2)->default(0);
            $table->integer('grace_period_days')->default(0);
            $table->decimal('penalty_rate_per_day', 5, 2)->default(0);
            $table->json('eligibility_criteria')->nullable();
            $table->json('required_documents')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'loan_type']);
        });

        Schema::create('sacco_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('loan_product_id')->nullable()->constrained('sacco_loan_products')->nullOnDelete();
            $table->string('loan_number')->unique();
            $table->string('application_number')->nullable()->unique();
            $table->string('loan_type')->default('personal');
            $table->decimal('principal_amount_ugx', 15, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('total_interest_ugx', 15, 2)->default(0);
            $table->decimal('total_payable_ugx', 15, 2)->default(0);
            $table->decimal('amount_paid_ugx', 15, 2)->default(0);
            $table->decimal('balance_remaining_ugx', 15, 2)->default(0);
            $table->integer('tenure_months')->default(1);
            $table->integer('duration_months')->default(1);
            $table->decimal('monthly_installment_ugx', 15, 2)->default(0);
            $table->date('disbursement_date')->nullable();
            $table->date('first_payment_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->unsignedInteger('guarantors_required')->default(0);
            $table->unsignedInteger('guarantors_approved')->default(0);
            $table->text('purpose')->nullable();
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disbursement_method')->nullable();
            $table->string('disbursement_reference')->nullable();
            $table->text('disbursement_notes')->nullable();
            $table->json('bank_details')->nullable();
            $table->json('mobile_money_details')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->boolean('auto_deduct')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->decimal('principal_amount', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('processing_fee', 15, 2)->default(0);
            $table->decimal('insurance_fee', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('monthly_repayment', 15, 2)->default(0);
            $table->decimal('outstanding_balance', 15, 2)->default(0);
            $table->integer('term_months')->default(1);
            $table->integer('repayment_period_months')->default(1);
            $table->unsignedInteger('installments_paid')->default(0);
            $table->unsignedInteger('installments_remaining')->default(0);
            $table->json('guarantors')->nullable();
            $table->date('applied_date')->nullable();
            $table->date('approved_date')->nullable();
            $table->date('disbursed_date')->nullable();
            $table->date('application_date')->nullable();
            $table->date('first_repayment_date')->nullable();
            $table->date('last_repayment_date')->nullable();
            $table->timestamp('fully_repaid_at')->nullable();
            $table->boolean('auto_deduct_from_royalties')->default(false);
            $table->decimal('royalty_deduction_percentage', 5, 2)->nullable();
            $table->date('next_payment_date')->nullable();
            $table->decimal('next_payment_amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index(['loan_type', 'status']);
            $table->index(['loan_product_id', 'status']);
        });

        Schema::create('sacco_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('payment_code')->unique();
            $table->foreignId('loan_id')->constrained('sacco_loans')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->unsignedInteger('repayment_number')->nullable();
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->decimal('principal_amount', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('penalty_amount', 15, 2)->default(0);
            $table->decimal('amount_ugx', 15, 2)->default(0);
            $table->decimal('principal_paid_ugx', 15, 2)->default(0);
            $table->decimal('interest_paid_ugx', 15, 2)->default(0);
            $table->decimal('penalty_paid_ugx', 15, 2)->default(0);
            $table->timestamp('payment_date')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_early_payment')->default(false);
            $table->boolean('is_late_payment')->default(false);
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['loan_id', 'due_date']);
            $table->index(['member_id', 'payment_date']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('sacco_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('sacco_loans')->cascadeOnDelete();
            $table->foreignId('guarantor_member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->decimal('guaranteed_amount', 15, 2);
            $table->string('status')->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'guarantor_member_id']);
            $table->index(['loan_id', 'status']);
        });

        Schema::create('sacco_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->unique()->constrained('sacco_members')->cascadeOnDelete();
            $table->integer('total_shares')->default(0);
            $table->decimal('share_value_ugx', 15, 2)->default(0);
            $table->decimal('total_value_ugx', 15, 2)->default(0);
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sacco_share_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('transaction_code')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->foreignId('share_id')->constrained('sacco_shares')->cascadeOnDelete();
            $table->string('type');
            $table->integer('shares_quantity');
            $table->decimal('price_per_share_ugx', 15, 2);
            $table->decimal('total_amount_ugx', 15, 2);
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['share_id', 'type']);
        });

        Schema::create('sacco_dividends', function (Blueprint $table) {
            $table->id();
            $table->integer('dividend_year')->unique();
            $table->decimal('total_profit', 15, 2)->default(0);
            $table->decimal('dividend_rate', 5, 2)->default(0);
            $table->date('declaration_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('status')->default('declared');
            $table->timestamps();
        });

        Schema::create('sacco_member_dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dividend_id')->constrained('sacco_dividends')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->decimal('shares_amount', 15, 2)->default(0);
            $table->decimal('dividend_amount', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['dividend_id', 'member_id']);
        });

        Schema::create('sacco_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('category')->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('sacco_contributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('contribution_number')->unique();
            $table->string('type')->default('monthly');
            $table->decimal('amount_ugx', 15, 2);
            $table->string('payment_method')->default('mobile_money');
            $table->string('transaction_reference')->nullable();
            $table->date('contribution_date');
            $table->string('period')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'contribution_date']);
            $table->index(['status', 'contribution_date']);
        });

        Schema::create('sacco_fines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('fine_number')->unique();
            $table->string('reason');
            $table->text('description')->nullable();
            $table->decimal('amount_ugx', 15, 2);
            $table->decimal('amount_paid_ugx', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('waiver_reason')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });

        Schema::create('sacco_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('sacco_savings_accounts')->nullOnDelete();
            $table->string('request_number')->unique();
            $table->decimal('amount_ugx', 15, 2);
            $table->decimal('fee_ugx', 15, 2)->default(0);
            $table->decimal('net_amount_ugx', 15, 2)->default(0);
            $table->string('withdrawal_method')->default('mobile_money');
            $table->string('phone_number')->nullable();
            $table->string('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });

        Schema::create('sacco_goals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('type')->default('general');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('target_amount', 15, 2);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('UGX');
            $table->date('deadline')->nullable();
            $table->string('status')->default('active');
            $table->string('visibility')->default('private');
            $table->decimal('monthly_target', 15, 2)->nullable();
            $table->boolean('auto_deposit')->default(false);
            $table->decimal('auto_deposit_percentage', 5, 2)->nullable();
            $table->boolean('credit_conversion_enabled')->default(false);
            $table->json('production_details')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['type', 'status']);
        });

        Schema::create('sacco_goal_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('goal_id')->constrained('sacco_goals')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('sacco_members')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();

            $table->index(['goal_id', 'created_at']);
            $table->index(['member_id', 'type']);
        });

        Schema::create('sacco_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained('sacco_accounts')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('sacco_members')->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('sacco_loans')->nullOnDelete();
            $table->string('transaction_number')->nullable()->unique();
            $table->string('transaction_reference')->unique();
            $table->string('transaction_type');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->default('completed');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'transaction_type']);
            $table->index(['account_id', 'transaction_date']);
            $table->index(['loan_id', 'transaction_type']);
            $table->index(['status', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_transactions');
        Schema::dropIfExists('sacco_goal_transactions');
        Schema::dropIfExists('sacco_goals');
        Schema::dropIfExists('sacco_withdrawal_requests');
        Schema::dropIfExists('sacco_fines');
        Schema::dropIfExists('sacco_contributions');
        Schema::dropIfExists('sacco_settings');
        Schema::dropIfExists('sacco_member_dividends');
        Schema::dropIfExists('sacco_dividends');
        Schema::dropIfExists('sacco_share_transactions');
        Schema::dropIfExists('sacco_shares');
        Schema::dropIfExists('sacco_guarantors');
        Schema::dropIfExists('sacco_loan_repayments');
        Schema::dropIfExists('sacco_loans');
        Schema::dropIfExists('sacco_loan_products');
        Schema::dropIfExists('sacco_savings_transactions');
        Schema::dropIfExists('sacco_savings_accounts');
        Schema::dropIfExists('sacco_accounts');
        Schema::dropIfExists('sacco_members');
    }
};
