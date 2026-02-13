<?php

namespace App\Http\Requests\Sacco;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Sacco\Models\SaccoLoanProduct;
use App\Modules\Sacco\Models\SaccoMember;

class LoanApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && SaccoMember::where('user_id', auth()->id())->where('status', 'active')->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $product = SaccoLoanProduct::find($this->loan_product_id);

        return [
            'loan_product_id' => [
                'required',
                'exists:sacco_loan_products,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:' . ($product->min_amount ?? 10000),
                'max:' . ($product->max_amount ?? 50000000),
            ],
            'duration_months' => [
                'required',
                'integer',
                'min:' . ($product->min_duration_months ?? 1),
                'max:' . ($product->max_duration_months ?? 60),
            ],
            'purpose' => [
                'required',
                'string',
                'max:1000',
                'min:20',
            ],
            'guarantors' => [
                'required',
                'array',
                'min:' . ($product->min_guarantors ?? config('sacco.minimum_guarantors', 2)),
            ],
            'guarantors.*' => [
                'exists:sacco_members,id',
                'different:' . auth()->id(),
            ],
            'collateral_description' => [
                'nullable',
                'string',
                'max:1000',
                'required_if:loan_product_id,' . $product?->requires_collateral,
            ],
            'collateral_value' => [
                'nullable',
                'numeric',
                'min:0',
                'required_if:loan_product_id,' . $product?->requires_collateral,
            ],
            'collateral_documents' => [
                'nullable',
                'array',
                'required_if:loan_product_id,' . $product?->requires_collateral,
            ],
            'collateral_documents.*' => [
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120', // 5MB
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        $product = SaccoLoanProduct::find($this->loan_product_id);

        return [
            'loan_product_id.required' => 'Please select a loan product',
            'loan_product_id.exists' => 'Invalid loan product selected',
            'amount.min' => 'Minimum loan amount is UGX ' . number_format($product->min_amount ?? 10000),
            'amount.max' => 'Maximum loan amount is UGX ' . number_format($product->max_amount ?? 50000000),
            'duration_months.min' => 'Minimum loan duration is ' . ($product->min_duration_months ?? 1) . ' months',
            'duration_months.max' => 'Maximum loan duration is ' . ($product->max_duration_months ?? 60) . ' months',
            'purpose.min' => 'Please provide a detailed purpose (at least 20 characters)',
            'guarantors.min' => 'You need at least ' . ($product->min_guarantors ?? config('sacco.minimum_guarantors', 2)) . ' guarantors',
            'guarantors.*.exists' => 'One or more selected guarantors are invalid',
            'guarantors.*.different' => 'You cannot guarantee yourself',
            'collateral_description.required_if' => 'Collateral description is required for this loan type',
            'collateral_value.required_if' => 'Collateral value is required for this loan type',
            'collateral_documents.required_if' => 'Collateral documents are required for this loan type',
            'collateral_documents.*.mimes' => 'Collateral documents must be PDF, JPG, or PNG files',
            'collateral_documents.*.max' => 'Each document must not exceed 5MB',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'loan_product_id' => 'loan product',
            'duration_months' => 'loan duration',
            'purpose' => 'loan purpose',
            'collateral_description' => 'collateral description',
            'collateral_value' => 'collateral value',
            'collateral_documents' => 'collateral documents',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $member = SaccoMember::where('user_id', auth()->id())->first();
            
            if (!$member) {
                $validator->errors()->add('member', 'You are not a SACCO member');
                return;
            }

            // Check if member has any active loans
            if ($member->loans()->where('status', 'active')->count() >= config('sacco.max_concurrent_loans', 3)) {
                $validator->errors()->add('loans', 'You have reached the maximum number of concurrent loans');
            }

            // Check if member has any overdue loans
            if ($member->loans()->where('status', 'overdue')->exists()) {
                $validator->errors()->add('loans', 'You have overdue loans. Please clear them before applying for a new loan');
            }

            // Check guarantors uniqueness
            $guarantors = $this->guarantors ?? [];
            if (count($guarantors) !== count(array_unique($guarantors))) {
                $validator->errors()->add('guarantors', 'You cannot select the same guarantor multiple times');
            }
        });
    }
}
