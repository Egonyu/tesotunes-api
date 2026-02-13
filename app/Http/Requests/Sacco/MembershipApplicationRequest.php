<?php

namespace App\Http\Requests\Sacco;

use Illuminate\Foundation\Http\FormRequest;

class MembershipApplicationRequest extends FormRequest
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
            'national_id' => [
                'required',
                'string',
                'max:50',
                'unique:sacco_members,national_id',
            ],
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'regex:/^(\+256|0)[0-9]{9}$/', // Uganda phone format
            ],
            'date_of_birth' => [
                'required',
                'date',
                'before:18 years ago', // Must be 18 or older
                'after:100 years ago',
            ],
            'address' => [
                'required',
                'string',
                'max:500',
            ],
            'occupation' => [
                'required',
                'string',
                'max:255',
            ],
            'employer' => [
                'nullable',
                'string',
                'max:255',
            ],
            'monthly_income' => [
                'required',
                'numeric',
                'min:0',
                'max:999999999',
            ],
            'next_of_kin_name' => [
                'required',
                'string',
                'max:255',
            ],
            'next_of_kin_phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^(\+256|0)[0-9]{9}$/',
            ],
            'next_of_kin_relationship' => [
                'required',
                'string',
                'max:100',
            ],
            'initial_shares' => [
                'required',
                'integer',
                'min:' . config('sacco.minimum_shares', 1),
                'max:' . config('sacco.maximum_initial_shares', 1000),
            ],
            'agree_terms' => [
                'required',
                'accepted',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'national_id.unique' => 'This National ID is already registered with SACCO',
            'phone_number.regex' => 'Please enter a valid Uganda phone number (e.g., 0700000000 or +256700000000)',
            'date_of_birth.before' => 'You must be at least 18 years old to join SACCO',
            'next_of_kin_phone.regex' => 'Please enter a valid Uganda phone number for next of kin',
            'initial_shares.min' => 'Minimum initial shares is ' . config('sacco.minimum_shares', 1),
            'initial_shares.max' => 'Maximum initial shares for new members is ' . config('sacco.maximum_initial_shares', 1000),
            'agree_terms.accepted' => 'You must agree to the terms and conditions',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'national_id' => 'National ID',
            'phone_number' => 'phone number',
            'date_of_birth' => 'date of birth',
            'monthly_income' => 'monthly income',
            'next_of_kin_name' => 'next of kin name',
            'next_of_kin_phone' => 'next of kin phone',
            'next_of_kin_relationship' => 'next of kin relationship',
            'initial_shares' => 'initial shares',
            'agree_terms' => 'terms and conditions',
        ];
    }
}
