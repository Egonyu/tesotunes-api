<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SACCO Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the LineOne Music Platform SACCO
    | (Savings and Credit Cooperative Organization) feature.
    |
    */

    'enabled' => env('SACCO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Membership Configuration
    |--------------------------------------------------------------------------
    */
    'membership' => [
        'fee' => env('SACCO_MEMBERSHIP_FEE', 5000), // UGX
        'min_share_capital' => env('SACCO_MIN_SHARE_CAPITAL', 20000), // UGX
        'approval_required' => env('SACCO_APPROVAL_REQUIRED', true),
        'auto_approve_verified_artists' => env('SACCO_AUTO_APPROVE_ARTISTS', false),
        'member_number_prefix' => env('SACCO_MEMBERSHIP_PREFIX', 'SACCO'),
        'kyc_required' => env('SACCO_KYC_REQUIRED', true),
        'min_age' => env('SACCO_MIN_AGE', 18),
    ],

    /*
    |--------------------------------------------------------------------------
    | Savings Configuration
    |--------------------------------------------------------------------------
    */
    'savings' => [
        'min_monthly_deposit' => env('SACCO_MIN_MONTHLY_DEPOSIT', 20000), // UGX
        'min_balance' => env('SACCO_MIN_BALANCE', 10000), // UGX
        'interest_rate' => env('SACCO_SAVINGS_INTEREST_RATE', 6.0), // % annually
        'interest_calculation_method' => env('SACCO_INTEREST_METHOD', 'daily_balance'), // daily_balance, monthly_average
        'interest_payout_frequency' => env('SACCO_INTEREST_PAYOUT', 'annually'), // monthly, quarterly, annually
        'dormant_account_period_months' => env('SACCO_DORMANT_PERIOD', 12),
        'max_daily_withdrawal' => env('SACCO_MAX_DAILY_WITHDRAWAL', 1000000), // UGX
        'withdrawal_fee_percentage' => env('SACCO_WITHDRAWAL_FEE', 0), // %
    ],

    /*
    |--------------------------------------------------------------------------
    | Share Capital Configuration
    |--------------------------------------------------------------------------
    */
    'share_capital' => [
        'share_value' => env('SACCO_SHARE_VALUE', 10000), // UGX per share
        'min_shares' => env('SACCO_MIN_SHARES', 2),
        'max_shares' => env('SACCO_MAX_SHARES', 1000),
        'can_withdraw_shares' => env('SACCO_CAN_WITHDRAW_SHARES', false),
        'share_transfer_allowed' => env('SACCO_SHARE_TRANSFER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loan Configuration
    |--------------------------------------------------------------------------
    */
    'loans' => [
        'max_loan_to_savings_ratio' => env('SACCO_MAX_LOAN_RATIO', 3.0), // 3x savings
        'default_interest_rate' => env('SACCO_LOAN_INTEREST_RATE', 12.0), // % annually
        'min_credit_score' => env('SACCO_MIN_CREDIT_SCORE', 400),
        'processing_fee_percentage' => env('SACCO_PROCESSING_FEE', 2.0), // % of loan
        'insurance_fee_percentage' => env('SACCO_INSURANCE_FEE', 1.0), // % of loan
        'grace_period_days' => env('SACCO_GRACE_PERIOD', 7),
        'penalty_rate_per_day' => env('SACCO_PENALTY_RATE', 0.1), // % per day
        'max_penalty_percentage' => env('SACCO_MAX_PENALTY', 10), // % of outstanding
        'min_loan_amount' => env('SACCO_MIN_LOAN_AMOUNT', 50000), // UGX
        'max_loan_amount' => env('SACCO_MAX_LOAN_AMOUNT', 10000000), // UGX
        'min_repayment_months' => env('SACCO_MIN_REPAYMENT_MONTHS', 3),
        'max_repayment_months' => env('SACCO_MAX_REPAYMENT_MONTHS', 36),
        'approval_workflow' => [
            'under_500k' => 'manager', // Single approval
            '500k_to_2m' => 'board_member', // Board member approval
            'over_2m' => 'board_vote', // Full board vote required
        ],
        'auto_default_days' => env('SACCO_AUTO_DEFAULT_DAYS', 90), // Mark as defaulted after 90 days
        'allow_loan_restructuring' => env('SACCO_ALLOW_RESTRUCTURING', true),
        'allow_top_up_loans' => env('SACCO_ALLOW_TOP_UP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loan Products Configuration
    |--------------------------------------------------------------------------
    */
    'loan_products' => [
        'personal' => [
            'name' => 'Personal Loan',
            'interest_rate' => 12.0,
            'max_amount' => 5000000,
            'max_months' => 24,
            'requires_guarantor' => true,
            'min_guarantors' => 2,
        ],
        'equipment' => [
            'name' => 'Equipment Financing',
            'interest_rate' => 10.0,
            'max_amount' => 10000000,
            'max_months' => 36,
            'requires_guarantor' => true,
            'min_guarantors' => 1,
        ],
        'tour_financing' => [
            'name' => 'Tour/Event Financing',
            'interest_rate' => 15.0,
            'max_amount' => 3000000,
            'max_months' => 12,
            'requires_guarantor' => true,
            'min_guarantors' => 2,
        ],
        'emergency' => [
            'name' => 'Emergency Loan',
            'interest_rate' => 8.0,
            'max_amount' => 500000,
            'max_months' => 6,
            'requires_guarantor' => false,
            'min_guarantors' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Royalty Deduction Configuration (Artist-Specific)
    |--------------------------------------------------------------------------
    */
    'royalty_deduction' => [
        'enabled' => env('SACCO_ROYALTY_DEDUCTION', true),
        'default_percentage' => env('SACCO_DEFAULT_ROYALTY_DEDUCTION', 30), // % of royalties
        'min_percentage' => 10,
        'max_percentage' => 70,
        'auto_deduct' => env('SACCO_AUTO_DEDUCT_ROYALTIES', false),
        'require_consent' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dividend Configuration
    |--------------------------------------------------------------------------
    */
    'dividend' => [
        'distribution_percentage' => env('SACCO_DIVIDEND_DISTRIBUTION', 70), // 70% of profit
        'retained_earnings_percentage' => env('SACCO_RETAINED_EARNINGS', 30), // 30% retained
        'withholding_tax_percentage' => env('SACCO_DIVIDEND_TAX', 15), // 15% WHT
        'min_membership_months_for_dividend' => env('SACCO_MIN_MONTHS_FOR_DIVIDEND', 6),
        'calculation_method' => 'share_capital', // share_capital, equal_distribution
        'payout_month' => env('SACCO_DIVIDEND_PAYOUT_MONTH', 3), // March
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixed Deposit Configuration
    |--------------------------------------------------------------------------
    */
    'fixed_deposit' => [
        'enabled' => env('SACCO_FIXED_DEPOSIT_ENABLED', true),
        'min_amount' => env('SACCO_FD_MIN_AMOUNT', 100000), // UGX
        'min_term_months' => 6,
        'max_term_months' => 60,
        'interest_rates' => [
            '6_months' => 8.0,
            '12_months' => 10.0,
            '24_months' => 12.0,
            '36_months' => 14.0,
        ],
        'early_withdrawal_penalty_percentage' => 5.0,
        'allow_partial_withdrawal' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Configuration
    |--------------------------------------------------------------------------
    */
    'transactions' => [
        'reference_prefix' => env('SACCO_TRANSACTION_PREFIX', 'SACT'),
        'require_approval_above' => env('SACCO_REQUIRE_APPROVAL_ABOVE', 500000), // UGX
        'dual_authorization_above' => env('SACCO_DUAL_AUTH_ABOVE', 2000000), // UGX
        'max_daily_transactions' => env('SACCO_MAX_DAILY_TRANSACTIONS', 10),
        'transaction_fee' => env('SACCO_TRANSACTION_FEE', 0), // UGX
        'enable_sms_notifications' => env('SACCO_SMS_NOTIFICATIONS', true),
        'enable_email_notifications' => env('SACCO_EMAIL_NOTIFICATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile Money Integration
    |--------------------------------------------------------------------------
    */
    'mobile_money' => [
        'enabled' => env('SACCO_MOBILE_MONEY_ENABLED', true),
        'providers' => ['mtn', 'airtel'],
        'deposit_charge_bearer' => env('SACCO_DEPOSIT_CHARGE_BEARER', 'member'), // member, sacco, split
        'withdrawal_charge_bearer' => env('SACCO_WITHDRAWAL_CHARGE_BEARER', 'member'),
        'min_mobile_money_deposit' => 5000,
        'max_mobile_money_deposit' => 5000000,
        'auto_credit_on_payment' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guarantor Configuration
    |--------------------------------------------------------------------------
    */
    'guarantor' => [
        'min_savings_to_guarantee' => env('SACCO_MIN_SAVINGS_TO_GUARANTEE', 100000), // UGX
        'max_guarantee_to_savings_ratio' => env('SACCO_MAX_GUARANTEE_RATIO', 2.0), // 2x
        'max_active_guarantees' => env('SACCO_MAX_ACTIVE_GUARANTEES', 3),
        'require_guarantor_acceptance' => true,
        'guarantor_notification_method' => 'sms_and_email',
        'guarantor_liability_percentage' => 100, // Full liability
    ],

    /*
    |--------------------------------------------------------------------------
    | Governance Configuration
    |--------------------------------------------------------------------------
    */
    'governance' => [
        'board_size' => env('SACCO_BOARD_SIZE', 7),
        'board_term_years' => env('SACCO_BOARD_TERM', 3),
        'agm_month' => env('SACCO_AGM_MONTH', 12), // December
        'quorum_percentage' => env('SACCO_QUORUM_PERCENTAGE', 50), // 50% of members
        'voting_enabled' => env('SACCO_VOTING_ENABLED', true),
        'proxy_voting_allowed' => env('SACCO_PROXY_VOTING', true),
        'election_method' => 'electronic', // electronic, paper, hybrid
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting Configuration
    |--------------------------------------------------------------------------
    */
    'reporting' => [
        'financial_year_start_month' => env('SACCO_FY_START_MONTH', 1), // January
        'monthly_statements' => env('SACCO_MONTHLY_STATEMENTS', true),
        'quarterly_reports' => env('SACCO_QUARTERLY_REPORTS', true),
        'annual_reports' => env('SACCO_ANNUAL_REPORTS', true),
        'statement_delivery_method' => 'email', // email, sms, portal
        'enable_member_portal' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & Compliance
    |--------------------------------------------------------------------------
    */
    'security' => [
        'enable_2fa_for_withdrawals' => env('SACCO_2FA_WITHDRAWALS', true),
        'enable_2fa_for_loans' => env('SACCO_2FA_LOANS', false),
        'session_timeout_minutes' => env('SACCO_SESSION_TIMEOUT', 30),
        'password_expiry_days' => env('SACCO_PASSWORD_EXPIRY', 90),
        'audit_retention_days' => env('SACCO_AUDIT_RETENTION', 2555), // 7 years
        'encrypt_sensitive_data' => true,
        'require_pin_for_transactions' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications Configuration
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'loan_application_received' => true,
        'loan_approved' => true,
        'loan_rejected' => true,
        'loan_disbursed' => true,
        'repayment_due_reminder_days' => [7, 3, 1], // Days before due date
        'repayment_overdue_notification' => true,
        'deposit_confirmation' => true,
        'withdrawal_confirmation' => true,
        'dividend_credited' => true,
        'low_balance_alert' => true,
        'low_balance_threshold' => 5000, // UGX
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance & Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_concurrent_loans' => 1,
        'max_lifetime_loans' => null, // No limit
        'cooldown_period_between_loans_months' => 0,
        'max_loan_restructures' => 2,
        'max_pending_applications' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration Settings
    |--------------------------------------------------------------------------
    */
    'integrations' => [
        'umro' => [
            'enabled' => env('SACCO_UMRO_INTEGRATION', false),
            'royalty_verification' => false,
        ],
        'credit_bureau' => [
            'enabled' => env('SACCO_CREDIT_BUREAU', false),
            'check_on_application' => false,
            'report_defaults' => true,
        ],
        'accounting_software' => [
            'enabled' => env('SACCO_ACCOUNTING_INTEGRATION', false),
            'provider' => env('SACCO_ACCOUNTING_PROVIDER', null), // quickbooks, xero
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */
    'features' => [
        'group_savings' => env('SACCO_GROUP_SAVINGS', false),
        'insurance_products' => env('SACCO_INSURANCE', false),
        'investment_funds' => env('SACCO_INVESTMENT_FUNDS', false),
        'microinsurance' => env('SACCO_MICROINSURANCE', false),
        'asset_financing' => env('SACCO_ASSET_FINANCING', false),
        'international_transfers' => env('SACCO_INTERNATIONAL', false),
        'member_to_member_transfers' => env('SACCO_P2P_TRANSFERS', true),
        'loan_marketplace' => env('SACCO_LOAN_MARKETPLACE', false),
    ],

];
