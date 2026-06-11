<?php

namespace App\Http\Requests\Api\Capabilities;

use Illuminate\Foundation\Http\FormRequest;

class ApplyOrganizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'city' => 'nullable|string|max:255',
            'experience_summary' => 'required|string|min:30|max:2000',
            'website_url' => 'nullable|url|max:255',
            'social_links' => 'nullable|array',
            'social_links.*' => 'nullable|string|max:255',
            'expected_events_per_year' => 'nullable|integer|min:1|max:365',
        ];
    }

    public function messages(): array
    {
        return [
            'experience_summary.min' => 'Tell us a bit more about the events you have run or plan to run (at least 30 characters).',
        ];
    }
}
