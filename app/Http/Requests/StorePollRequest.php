<?php

namespace App\Http\Requests;

use App\Models\Modules\Forum\Poll;
use App\Models\Modules\Forum\PollQuestion;
use Illuminate\Foundation\Http\FormRequest;

class StorePollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Poll metadata
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'poll_type' => ['required', 'string', 'in:'.implode(',', [
                Poll::TYPE_GENERAL,
                Poll::TYPE_SONG_BATTLE,
                Poll::TYPE_ARTIST_CONTEST,
                Poll::TYPE_RESEARCH_SURVEY,
            ])],
            'category' => ['nullable', 'string', 'in:'.implode(',', array_keys(Poll::CATEGORIES))],
            'audience' => ['nullable', 'string', 'in:'.implode(',', [
                Poll::AUDIENCE_ALL,
                Poll::AUDIENCE_USERS,
                Poll::AUDIENCE_ARTISTS,
            ])],
            'allow_guest_responses' => ['nullable', 'boolean'],
            'show_results_before_completion' => ['nullable', 'boolean'],
            'is_anonymous' => ['nullable', 'boolean'],
            'credits_reward' => ['nullable', 'integer', 'min:1', 'max:20'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['nullable', 'string', 'in:draft,active'],

            // Questions
            'questions' => ['required', 'array', 'min:1', 'max:30'],
            'questions.*.question_text' => ['required', 'string', 'max:500'],
            'questions.*.description' => ['nullable', 'string', 'max:1000'],
            'questions.*.question_type' => ['required', 'string', 'in:'.implode(',', PollQuestion::TYPES)],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.allow_multiple' => ['nullable', 'boolean'],
            'questions.*.settings' => ['nullable', 'array'],
            'questions.*.settings.scale_min' => ['nullable', 'integer', 'min:0'],
            'questions.*.settings.scale_max' => ['nullable', 'integer', 'min:1'],
            'questions.*.settings.min_label' => ['nullable', 'string', 'max:100'],
            'questions.*.settings.max_label' => ['nullable', 'string', 'max:100'],

            // Options (for multiple_choice / ranking questions)
            'questions.*.options' => ['nullable', 'array', 'min:2', 'max:10'],
            'questions.*.options.*.option_text' => ['required_with:questions.*.options', 'string', 'max:255'],
            'questions.*.options.*.image' => ['nullable', 'string', 'max:500'],

            // Typed options for song_battle
            'questions.*.options.*.song_id' => ['nullable', 'exists:songs,id'],

            // Typed options for artist_contest
            'questions.*.options.*.artist_id' => ['nullable', 'exists:artists,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'questions.required' => 'At least one question is required.',
            'questions.*.question_text.required' => 'Each question must have text.',
            'questions.*.question_type.in' => 'Question type must be one of: '.implode(', ', PollQuestion::TYPES).'.',
            'questions.*.options.min' => 'Choice-based questions need at least 2 options.',
            'questions.*.options.*.option_text.required_with' => 'Each option must have text.',
            'ends_at.after' => 'End date must be after start date.',
        ];
    }

    public function prepareForValidation(): void
    {
        // Default status to draft when not provided
        if (! $this->has('status')) {
            $this->merge(['status' => Poll::STATUS_DRAFT]);
        }

        // Research surveys allow guests by default; community polls also default to true
        if (! $this->has('allow_guest_responses')) {
            $this->merge(['allow_guest_responses' => true]);
        }
    }
}
