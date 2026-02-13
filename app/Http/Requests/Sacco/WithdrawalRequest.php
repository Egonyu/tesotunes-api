<?php

namespace App\Http\Requests\Sacco;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Sacco\Models\SaccoAccount;

class WithdrawalRequest extends FormRequest
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
                'min:' . config('sacco.minimum_withdrawal', 1000),
                'max:' . config('sacco.maximum_withdrawal', 50000000),
            ],
            'withdrawal_method' => [
                'required',
                'in:mobile_money,bank_transfer,cash',
            ],
            'mobile_number' => [
                'nullable',
                'string',
                'regex:/^(\+256|0)[0-9]{9}$/',
                'required_if:withdrawal_method,mobile_money',
            ],
            'bank_name' => [
                'nullable',
                'string',
                'max:100',
                'required_if:withdrawal_method,bank_transfer',
            ],
            'bank_account_number' => [
                'nullable',
                'string',
                'max:50',
                'required_if:withdrawal_method,bank_transfer',
            ],
            'bank_account_name' => [
                'nullable',
                'string',
                'max:255',
                'required_if:withdrawal_method,bank_transfer',
            ],
            'reason' => [
                'required',
                'string',
                'max:500',
                'min:10',
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
            'amount.min' => 'Minimum withdrawal amount is UGX ' . number_format(config('sacco.minimum_withdrawal', 1000)),
            'amount.max' => 'Maximum withdrawal amount is UGX ' . number_format(config('sacco.maximum_withdrawal', 50000000)),
            'withdrawal_method.required' => 'Please select a withdrawal method',
            'withdrawal_method.in' => 'Invalid withdrawal method selected',
            'mobile_number.required_if' => 'Mobile number is required for mobile money withdrawals',
            'mobile_number.regex' => 'Please enter a valid Uganda phone number',
            'bank_name.required_if' => 'Bank name is required for bank transfers',
            'bank_account_number.required_if' => 'Bank account number is required for bank transfers',
            'bank_account_name.required_if' => 'Bank account name is required for bank transfers',
            'reason.required' => 'Please provide a reason for withdrawal',
            'reason.min' => 'Please provide a detailed reason (at least 10 characters)',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'account_id' => 'account',
            'withdrawal_method' => 'withdrawal method',
            'mobile_number' => 'mobile number',
            'bank_name' => 'bank name',
            'bank_account_number' => 'bank account number',
            'bank_account_name' => 'bank account name',
            'reason' => 'withdrawal reason',
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
                    return;
                }

                // Check if account allows withdrawals
                if ($account && !in_array($account->account_type, ['savings'])) {
                    $validator->errors()->add('account_id', 'Withdrawals are only allowed from savings accounts');
                    return;
                }

                // Check if account is active
                if ($account && $account->status !== 'active') {
                    $validator->errors()->add('account_id', 'This account is not active');
                    return;
                }

                // Check sufficient balance
                if ($account && $this->amount) {
                    $minimumBalance = config('sacco.minimum_balance', 5000);
                    $availableBalance = $account->balance - $minimumBalance;

                    if ($this->amount > $availableBalance) {
                        $validator->errors()->add('amount', 'Insufficient balance. Available: UGX ' . number_format($availableBalance) . ' (UGX ' . number_format($minimumBalance) . ' minimum balance required)');
                    }
                }

                // Check withdrawal frequency limits
                if ($account) {
                    $todayWithdrawals = $account->transactions()
                        ->where('transaction_type', 'withdrawal')
                        ->whereDate('created_at', today())
                        ->count();

                    $maxDailyWithdrawals = config('sacco.max_daily_withdrawals', 3);

                    if ($todayWithdrawals >= $maxDailyWithdrawals) {
                        $validator->errors()->add('amount', "You have reached the maximum of {$maxDailyWithdrawals} withdrawals per day");
                    }
                }
            }

            // Additional daily limit check for mobile money
            if ($this->withdrawal_method === 'mobile_money' && $this->amount > config('sacco.mobile_money_withdrawal_limit', 3000000)) {
                $validator->errors()->add('amount', 'Mobile money withdrawals are limited to UGX ' . number_format(config('sacco.mobile_money_withdrawal_limit', 3000000)) . ' per transaction');
            }
        });
    }
}
