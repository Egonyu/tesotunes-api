<?php

namespace App\Http\Requests\Api\Capabilities;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['grant', 'reject'])],
            'reason' => 'required_if:decision,reject|nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required_if' => 'A reason is required when rejecting an application.',
        ];
    }
}
