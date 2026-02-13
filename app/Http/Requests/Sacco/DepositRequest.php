<?php

namespace App\Http\Requests\Sacco;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Sacco\Models\SaccoAccount;

class DepositRequest extends FormRequest
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
        return [
            'account_id' => [
                'required',
                'exists:sacco_accounts,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:' . config('sacco.minimum_deposit', 1000),
                'max:' . config('sacco.maximum_deposit', 100000000),
            ],
            'payment_method' => [
                'required',
                'in:mobile_money,bank_transfer,cash',
            ],
            'payment_reference' => [
                'nullable',
                'string',
                'max:100',
                'required_if:payment_method,mobile_money,bank_transfer',
            ],
            'mobile_number' => [
                'nullable',
                'string',
                'regex:/^(\+256|0)[0-9]{9}$/',
                'required_if:payment_method,mobile_money',
            ],
            'bank_name' => [
                'nullable',
                'string',
                'max:100',
                'required_if:payment_method,bank_transfer',
            ],
            'bank_account_number' => [
                'nullable',
                'string',
                'max:50',
                'required_if:payment_method,bank_transfer',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'account_id.required' => 'Please select an account',
            'account_id.exists' => 'Invalid account selected',
            'amount.min' => 'Minimum deposit amount is UGX ' . number_format(config('sacco.minimum_deposit', 1000)),
            'amount.max' => 'Maximum deposit amount is UGX ' . number_format(config('sacco.maximum_deposit', 100000000)),
            'payment_method.required' => 'Please select a payment method',
            'payment_method.in' => 'Invalid payment method selected',
            'payment_reference.required_if' => 'Payment reference is required for this payment method',
            'mobile_number.required_if' => 'Mobile number is required for mobile money payments',
            'mobile_number.regex' => 'Please enter a valid Uganda phone number',
            'bank_name.required_if' => 'Bank name is required for bank transfers',
            'bank_account_number.required_if' => 'Bank account number is required for bank transfers',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'account_id' => 'account',
            'payment_method' => 'payment method',
            'payment_reference' => 'payment reference',
            'mobile_number' => 'mobile number',
            'bank_name' => 'bank name',
            'bank_account_number' => 'bank account number',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Verify account belongs to the authenticated member
            if ($this->account_id) {
                $account = SaccoAccount::find($this->account_id);
                
                if ($account && $account->member->user_id !== auth()->id()) {
                    $validator->errors()->add('account_id', 'This account does not belong to you');
                }

                // Check if account type allows deposits using admin-configured account types
                if ($account) {
                    $accountTypeCode = strtoupper(substr($account->account_number ?? '', 0, 3));
                    $accountType = \App\Models\Sacco\SaccoAccountType::where('code', $accountTypeCode)->first();
                    
                    if ($accountType && !$accountType->allow_deposits) {
                        $validator->errors()->add('account_id', 'This account type does not accept deposits');
                    }
                    
                    if ($accountType && !$accountType->is_active) {
                        $validator->errors()->add('account_id', 'This account type is currently inactive');
                    }
                }

                // Check if account is active
                if ($account && $account->status !== 'active') {
                    $validator->errors()->add('account_id', 'This account is not active');
                }
            }

            // Additional daily limit check for mobile money
            if ($this->payment_method === 'mobile_money' && $this->amount > config('sacco.mobile_money_daily_limit', 5000000)) {
                $validator->errors()->add('amount', 'Mobile money deposits are limited to UGX ' . number_format(config('sacco.mobile_money_daily_limit', 5000000)) . ' per transaction');
            }
        });
    }
}
