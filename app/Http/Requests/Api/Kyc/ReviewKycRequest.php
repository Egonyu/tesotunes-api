<?php

namespace App\Http\Requests\Api\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user();

        return $admin !== null
            && $admin->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Decision is required (approve or reject).',
            'decision.in' => 'Decision must be either "approve" or "reject".',
            'reason.required_if' => 'A reason is required when rejecting.',
        ];
    }
}
