<?php

namespace App\Http\Requests;

use App\Services\TimelineFeedService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TimelineFeedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ! in_array($this->input('view'), ['saved', 'following'], true) || $this->user() !== null || auth('sanctum')->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view' => ['sometimes', Rule::in(['for-you', 'interests', 'latest', 'following', 'saved'])],
            'module' => ['nullable', Rule::in(TimelineFeedService::MODULES)],
            'interests' => ['sometimes', 'array', 'max:10'],
            'interests.*' => ['string', 'distinct', Rule::in(TimelineFeedService::MODULES)],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return ['interests.*.in' => 'Choose a supported content category.', 'view.in' => 'Choose For You, My interests, or Latest.'];
    }
}
