<?php

namespace App\Modules\Sacco\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaccoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed SACCO Loan Products
        $this->seedLoanProducts();

        // 2. Seed SACCO Settings
        $this->seedSaccoSettings();

        $this->command->info('SACCO initial data seeded successfully!');
    }

    /**
     * Seed loan products
     */
    private function seedLoanProducts(): void
    {
        $loanProducts = [
            [
                'name' => 'Personal Loan',
                'slug' => 'personal-loan',
                'description' => 'General purpose personal loan for members to meet their financial needs.',
                'loan_type' => 'personal',
                'min_amount' => 50000,
                'max_amount' => 5000000,
                'default_interest_rate' => 12.00,
                'min_repayment_months' => 3,
                'max_repayment_months' => 24,
                'processing_fee_percentage' => 2.00,
                'insurance_fee_percentage' => 1.00,
                'requires_guarantor' => true,
                'min_guarantors' => 2,
                'requires_collateral' => false,
                'min_savings_balance_required' => 50000,
                'max_loan_to_savings_ratio' => 3.00,
                'grace_period_days' => 7,
                'penalty_rate_per_day' => 0.10,
                'eligibility_criteria' => json_encode([
                    'min_membership_months' => 3,
                    'min_credit_score' => 400,
                    'no_outstanding_loans' => true,
                    'min_savings_balance' => 50000,
                ]),
                'required_documents' => json_encode([
                    'national_id',
                    'proof_of_income',
                    'guarantor_forms',
                ]),
                'terms_and_conditions' => 'Standard personal loan terms apply. Repayment must be made monthly. Late payments attract penalties.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Equipment Financing',
                'slug' => 'equipment-financing',
                'description' => 'Financing for musical instruments, studio equipment, and other professional gear.',
                'loan_type' => 'equipment',
                'min_amount' => 100000,
                'max_amount' => 10000000,
                'default_interest_rate' => 10.00,
                'min_repayment_months' => 6,
                'max_repayment_months' => 36,
                'processing_fee_percentage' => 1.50,
                'insurance_fee_percentage' => 1.50,
                'requires_guarantor' => true,
                'min_guarantors' => 1,
                'requires_collateral' => true,
                'min_savings_balance_required' => 100000,
                'max_loan_to_savings_ratio' => 3.00,
                'grace_period_days' => 7,
                'penalty_rate_per_day' => 0.10,
                'eligibility_criteria' => json_encode([
                    'min_membership_months' => 6,
                    'min_credit_score' => 450,
                    'verified_artist' => true,
                    'equipment_invoice_required' => true,
                ]),
                'required_documents' => json_encode([
                    'national_id',
                    'equipment_proforma_invoice',
                    'guarantor_forms',
                    'business_plan',
                ]),
                'terms_and_conditions' => 'Equipment must be insured. SACCO retains ownership until full repayment.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Tour/Event Financing',
                'slug' => 'tour-event-financing',
                'description' => 'Short-term financing for tours, concerts, and music events.',
                'loan_type' => 'tour_financing',
                'min_amount' => 100000,
                'max_amount' => 3000000,
                'default_interest_rate' => 15.00,
                'min_repayment_months' => 3,
                'max_repayment_months' => 12,
                'processing_fee_percentage' => 2.50,
                'insurance_fee_percentage' => 1.00,
                'requires_guarantor' => true,
                'min_guarantors' => 2,
                'requires_collateral' => false,
                'min_savings_balance_required' => 100000,
                'max_loan_to_savings_ratio' => 2.50,
                'grace_period_days' => 5,
                'penalty_rate_per_day' => 0.15,
                'eligibility_criteria' => json_encode([
                    'min_membership_months' => 6,
                    'min_credit_score' => 450,
                    'verified_artist' => true,
                    'event_confirmation_required' => true,
                    'min_followers' => 1000,
                ]),
                'required_documents' => json_encode([
                    'national_id',
                    'event_booking_confirmation',
                    'event_budget',
                    'guarantor_forms',
                    'artist_portfolio',
                ]),
                'terms_and_conditions' => 'Loan must be repaid within 3 months of event completion. Higher interest rate due to short-term nature.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Emergency Loan',
                'slug' => 'emergency-loan',
                'description' => 'Quick access emergency loan for urgent financial needs.',
                'loan_type' => 'emergency',
                'min_amount' => 20000,
                'max_amount' => 500000,
                'default_interest_rate' => 8.00,
                'min_repayment_months' => 1,
                'max_repayment_months' => 6,
                'processing_fee_percentage' => 1.00,
                'insurance_fee_percentage' => 0.50,
                'requires_guarantor' => false,
                'min_guarantors' => 0,
                'requires_collateral' => false,
                'min_savings_balance_required' => 30000,
                'max_loan_to_savings_ratio' => 1.50,
                'grace_period_days' => 3,
                'penalty_rate_per_day' => 0.10,
                'eligibility_criteria' => json_encode([
                    'min_membership_months' => 3,
                    'min_credit_score' => 400,
                    'no_overdue_loans' => true,
                    'max_per_year' => 2,
                ]),
                'required_documents' => json_encode([
                    'national_id',
                    'emergency_justification',
                ]),
                'terms_and_conditions' => 'Emergency loans are processed within 24 hours. Limited to 2 per year.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Business Development Loan',
                'slug' => 'business-loan',
                'description' => 'Financing for music business ventures, studio setup, or record label operations.',
                'loan_type' => 'business',
                'min_amount' => 500000,
                'max_amount' => 10000000,
                'default_interest_rate' => 14.00,
                'min_repayment_months' => 12,
                'max_repayment_months' => 36,
                'processing_fee_percentage' => 2.00,
                'insurance_fee_percentage' => 1.50,
                'requires_guarantor' => true,
                'min_guarantors' => 2,
                'requires_collateral' => true,
                'min_savings_balance_required' => 200000,
                'max_loan_to_savings_ratio' => 3.00,
                'grace_period_days' => 30,
                'penalty_rate_per_day' => 0.10,
                'eligibility_criteria' => json_encode([
                    'min_membership_months' => 12,
                    'min_credit_score' => 500,
                    'business_registration' => true,
                    'business_plan_required' => true,
                ]),
                'required_documents' => json_encode([
                    'national_id',
                    'business_registration_certificate',
                    'comprehensive_business_plan',
                    'financial_projections',
                    'guarantor_forms',
                    'collateral_documents',
                ]),
                'terms_and_conditions' => 'Business loans require detailed business plan and collateral. Grace period of 1 month for business setup.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Education/Training Loan',
                'slug' => 'education-loan',
                'description' => 'Financing for music education, workshops, masterclasses, and professional training.',
                'loan_type' => 'education',
                'min_amount' => 50000,
                'max_amount' => 2000000,
                'default_interest_rate' => 9.00,
                'min_repayment_months' => 6,
                'max_repayment_months' => 24,
                'processing_fee_percentage' => 1.50,
                'insurance_fee_percentage' => 0.50,
                'requires_guarantor' => true,
                'min_guarantors' => 1,
                'requires_collateral' => false,
                'min_savings_balance_required' => 50000,
                'max_loan_to_savings_ratio' => 2.50,
                'grace_period_days' => 60,
                'penalty_rate_per_day' => 0.10,
                'eligibility_criteria' => json_encode([
                    'min_membership_months' => 3,
                    'min_credit_score' => 400,
                    'admission_letter_required' => true,
                ]),
                'required_documents' => json_encode([
                    'national_id',
                    'admission_letter',
                    'fee_structure',
                    'guarantor_forms',
                    'academic_transcripts',
                ]),
                'terms_and_conditions' => 'Education loans have a grace period of 60 days for course completion. Favorable interest rates.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('sacco_loan_products')->insert($loanProducts);

        $this->command->info('Seeded ' . count($loanProducts) . ' loan products');
    }

    /**
     * Seed SACCO settings
     */
    private function seedSaccoSettings(): void
    {
        $settings = [
            // Membership Settings
            [
                'key' => 'membership_fee',
                'value' => '5000',
                'data_type' => 'decimal',
                'category' => 'membership',
                'description' => 'One-time membership fee in UGX',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'min_share_capital',
                'value' => '20000',
                'data_type' => 'decimal',
                'category' => 'membership',
                'description' => 'Minimum share capital contribution in UGX',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Savings Settings
            [
                'key' => 'min_monthly_deposit',
                'value' => '20000',
                'data_type' => 'decimal',
                'category' => 'savings',
                'description' => 'Minimum monthly savings deposit in UGX',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'savings_interest_rate',
                'value' => '6.0',
                'data_type' => 'decimal',
                'category' => 'savings',
                'description' => 'Annual interest rate on savings (%)',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'max_daily_withdrawal',
                'value' => '1000000',
                'data_type' => 'decimal',
                'category' => 'savings',
                'description' => 'Maximum daily withdrawal limit in UGX',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Loan Settings
            [
                'key' => 'max_loan_to_savings_ratio',
                'value' => '3.0',
                'data_type' => 'decimal',
                'category' => 'loans',
                'description' => 'Maximum loan amount as multiple of savings',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_loan_interest_rate',
                'value' => '12.0',
                'data_type' => 'decimal',
                'category' => 'loans',
                'description' => 'Default annual interest rate on loans (%)',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'min_credit_score',
                'value' => '400',
                'data_type' => 'integer',
                'category' => 'loans',
                'description' => 'Minimum credit score required for loan eligibility',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'loan_processing_fee',
                'value' => '2.0',
                'data_type' => 'decimal',
                'category' => 'loans',
                'description' => 'Loan processing fee as percentage of loan amount',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'grace_period_days',
                'value' => '7',
                'data_type' => 'integer',
                'category' => 'loans',
                'description' => 'Grace period in days after payment due date',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'penalty_rate_per_day',
                'value' => '0.1',
                'data_type' => 'decimal',
                'category' => 'loans',
                'description' => 'Penalty rate per day for overdue payments (%)',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Dividend Settings
            [
                'key' => 'dividend_distribution_percentage',
                'value' => '70',
                'data_type' => 'decimal',
                'category' => 'dividend',
                'description' => 'Percentage of profit distributed as dividends',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'dividend_withholding_tax',
                'value' => '15',
                'data_type' => 'decimal',
                'category' => 'dividend',
                'description' => 'Withholding tax on dividend payments (%)',
                'is_editable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Governance Settings
            [
                'key' => 'board_size',
                'value' => '7',
                'data_type' => 'integer',
                'category' => 'governance',
                'description' => 'Number of board members',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'board_term_years',
                'value' => '3',
                'data_type' => 'integer',
                'category' => 'governance',
                'description' => 'Board member term length in years',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'quorum_percentage',
                'value' => '50',
                'data_type' => 'decimal',
                'category' => 'governance',
                'description' => 'Minimum percentage of members for quorum',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Transaction Settings
            [
                'key' => 'require_approval_above',
                'value' => '500000',
                'data_type' => 'decimal',
                'category' => 'transactions',
                'description' => 'Transaction amount requiring approval (UGX)',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'dual_authorization_above',
                'value' => '2000000',
                'data_type' => 'decimal',
                'category' => 'transactions',
                'description' => 'Transaction amount requiring dual authorization (UGX)',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Notification Settings
            [
                'key' => 'enable_sms_notifications',
                'value' => 'true',
                'data_type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Enable SMS notifications for members',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'enable_email_notifications',
                'value' => 'true',
                'data_type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Enable email notifications for members',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Royalty Deduction Settings (Artist-specific)
            [
                'key' => 'enable_royalty_deduction',
                'value' => 'true',
                'data_type' => 'boolean',
                'category' => 'royalty',
                'description' => 'Enable automatic royalty deduction for loan repayment',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_royalty_deduction_percentage',
                'value' => '30',
                'data_type' => 'decimal',
                'category' => 'royalty',
                'description' => 'Default percentage of royalties for loan repayment',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // System Settings
            [
                'key' => 'sacco_name',
                'value' => 'LineOne Music SACCO',
                'data_type' => 'string',
                'category' => 'system',
                'description' => 'Official name of the SACCO',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'sacco_registration_number',
                'value' => 'SACCO/UG/2025/001',
                'data_type' => 'string',
                'category' => 'system',
                'description' => 'Official SACCO registration number',
                'is_editable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'financial_year_start_month',
                'value' => '1',
                'data_type' => 'integer',
                'category' => 'system',
                'description' => 'Financial year start month (1-12)',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('sacco_settings')->insert($settings);

        $this->command->info('Seeded ' . count($settings) . ' SACCO settings');
    }
}
