<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for payment processing validation
 *
 * Validates payment data including:
 * - Payment method specific validation
 * - Amount and currency validation
 * - User authorization
 */
class PaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'amount' => [
                'required',
                'numeric',
                'min:1000', // Minimum 1000 UGX
                'max:10000000', // Maximum 10M UGX
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(['UGX', 'USD', 'KES', 'TZS', 'EUR', 'GBP']),
            ],
            'payment_method' => [
                'required',
                'string',
                Rule::in([
                    'mtn_mobile_money',
                    'airtel_money',
                    'stripe',
                    'bank_transfer'
                ]),
            ],
            'description' => [
                'nullable',
                'string',
                'max:255',
            ],
            'subscription_plan_id' => [
                'nullable',
                'integer',
                'exists:subscription_plans,id',
            ],
        ];

        // Add method-specific validation rules
        $rules = array_merge($rules, $this->getMethodSpecificRules());

        return $rules;
    }

    /**
     * Get payment method specific validation rules
     */
    protected function getMethodSpecificRules(): array
    {
        $paymentMethod = $this->input('payment_method');

        switch ($paymentMethod) {
            case 'mtn_mobile_money':
            case 'airtel_money':
                return [
                    'phone_number' => [
                        'required',
                        'string',
                        'regex:/^(\+256|0)[7-9][0-9]{8}$/',
                    ],
                ];

            case 'stripe':
                return [
                    'token' => [
                        'required',
                        'string',
                    ],
                    'card_last_four' => [
                        'nullable',
                        'string',
                        'size:4',
                    ],
                ];

            case 'bank_transfer':
                return [
                    'account_number' => [
                        'required',
                        'string',
                        'max:50',
                    ],
                    'bank_code' => [
                        'required',
                        'string',
                        'max:10',
                    ],
                    'account_name' => [
                        'required',
                        'string',
                        'max:255',
                    ],
                ];

            default:
                return [];
        }
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Payment amount is required.',
            'amount.numeric' => 'Payment amount must be a valid number.',
            'amount.min' => 'Minimum payment amount is 1,000 UGX.',
            'amount.max' => 'Maximum payment amount is 10,000,000 UGX.',
            'currency.required' => 'Currency is required.',
            'currency.in' => 'Currency must be one of: UGX, USD, KES, TZS, EUR, GBP.',
            'payment_method.required' => 'Payment method is required.',
            'payment_method.in' => 'Invalid payment method selected.',
            'phone_number.required' => 'Phone number is required for mobile money payments.',
            'phone_number.regex' => 'Please enter a valid Ugandan phone number (e.g., +256700123456 or 0700123456).',

            'email.email' => 'Please enter a valid email address.',
            'token.required' => 'Payment token is required for card payments.',
            'account_number.required' => 'Account number is required for bank transfers.',
            'bank_code.required' => 'Bank code is required for bank transfers.',
            'account_name.required' => 'Account name is required for bank transfers.',
            'subscription_plan_id.exists' => 'Selected subscription plan does not exist.',
        ];
    }

    /**
     * Get custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'phone_number' => 'phone number',
            'account_number' => 'account number',
            'bank_code' => 'bank code',
            'account_name' => 'account name',
            'subscription_plan_id' => 'subscription plan',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean phone number format
        if ($this->has('phone_number')) {
            $phoneNumber = $this->phone_number;

            // Remove spaces and dashes
            $phoneNumber = preg_replace('/[\s\-]/', '', $phoneNumber);

            // Convert 0 prefix to +256
            if (str_starts_with($phoneNumber, '0')) {
                $phoneNumber = '+256' . substr($phoneNumber, 1);
            }

            $this->merge(['phone_number' => $phoneNumber]);
        }

        // Ensure currency is uppercase
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper($this->currency)]);
        }

        // Convert amount to appropriate format
        if ($this->has('amount')) {
            $this->merge(['amount' => (float) $this->amount]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate subscription plan is active if provided
            if ($this->filled('subscription_plan_id')) {
                $plan = \App\Models\SubscriptionPlan::find($this->subscription_plan_id);
                if ($plan && !$plan->is_active) {
                    $validator->errors()->add('subscription_plan_id', 'Selected subscription plan is not available.');
                }

                // Validate plan is available in user's region
                if ($plan && $plan->region !== 'ALL' && $plan->region !== auth()->user()->country) {
                    $validator->errors()->add('subscription_plan_id', 'This subscription plan is not available in your region.');
                }
            }

            // Validate payment method is available in user's region
            $paymentMethod = $this->input('payment_method');
            $userCountry = auth()->user()->country ?? 'UG';

            $regionalMethods = [
                'UG' => ['mtn_mobile_money', 'airtel_money', 'stripe'],
                'KE' => ['stripe'],
                'TZ' => ['stripe'],
                'INTL' => ['stripe'],
            ];

            $availableMethods = $regionalMethods[$userCountry] ?? $regionalMethods['INTL'];

            if (!in_array($paymentMethod, $availableMethods)) {
                $validator->errors()->add('payment_method', 'This payment method is not available in your region.');
            }

            // Validate amount based on currency and payment method
            $amount = $this->input('amount');
            $currency = $this->input('currency');

            if ($currency === 'USD' && $amount < 1) {
                $validator->errors()->add('amount', 'Minimum payment amount is $1 USD.');
            }

            // Mobile money specific validations
            if (in_array($paymentMethod, ['mtn_mobile_money', 'airtel_money'])) {
                if ($currency !== 'UGX') {
                    $validator->errors()->add('currency', 'Mobile money payments only support UGX currency.');
                }

                if ($amount > 5000000) { // 5M UGX limit for mobile money
                    $validator->errors()->add('amount', 'Mobile money payments have a maximum limit of 5,000,000 UGX.');
                }
            }
        });
    }
}