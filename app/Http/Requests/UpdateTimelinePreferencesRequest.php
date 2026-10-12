<?php

namespace App\Http\Requests;

use App\Services\TimelineFeedService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTimelinePreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'interests' => ['sometimes', 'array', 'max:9'],
            'interests.*' => ['string', 'distinct', Rule::in(TimelineFeedService::MODULES)],
            'action' => ['sometimes', Rule::in(['save', 'unsave', 'hide', 'unhide', 'restore-hidden'])],
            'identity' => [Rule::requiredIf(in_array($this->input('action'), ['save', 'unsave', 'hide', 'unhide'], true)), 'string', 'max:100', 'regex:/^(feed:[a-fA-F0-9-]{36}|(?:song|episode|post):[1-9][0-9]*)$/'],
        ];
    }

    public function messages(): array
    {
        return ['identity.regex' => 'Choose a valid timeline item.', 'interests.*.in' => 'Choose a supported interest.'];
    }
}
