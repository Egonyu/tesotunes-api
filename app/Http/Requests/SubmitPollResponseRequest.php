<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPollResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Keyed by question_id: { question_id => answer_payload }
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer', 'exists:poll_questions,id'],

            // Multiple-choice / ranking — array of option IDs
            'answers.*.option_ids' => ['nullable', 'array'],
            'answers.*.option_ids.*' => ['integer', 'exists:poll_options,id'],

            // Ranking — position per option_id
            'answers.*.ranking' => ['nullable', 'array'],
            'answers.*.ranking.*.option_id' => ['required_with:answers.*.ranking', 'integer', 'exists:poll_options,id'],
            'answers.*.ranking.*.position' => ['required_with:answers.*.ranking', 'integer', 'min:1'],

            // Free text
            'answers.*.answer_text' => ['nullable', 'string', 'max:2000'],

            // Rating / likert
            'answers.*.rating_value' => ['nullable', 'integer', 'min:0', 'max:10'],

            // Guest session token (cookie-based, sent by frontend for guests)
            'session_token' => ['nullable', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'answers.required' => 'You must provide answers.',
            'answers.*.question_id.exists' => 'One or more question IDs are invalid.',
            'answers.*.option_ids.*.exists' => 'One or more selected options are invalid.',
            'answers.*.answer_text.max' => 'Free-text answers may not exceed 2000 characters.',
            'answers.*.rating_value.min' => 'Rating must be at least :min.',
            'answers.*.rating_value.max' => 'Rating may not exceed :max.',
            'session_token.size' => 'Invalid guest session token.',
        ];
    }
}
