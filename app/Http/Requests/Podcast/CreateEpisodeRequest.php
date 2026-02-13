<?php

namespace App\Http\Requests\Podcast;

use Illuminate\Foundation\Http\FormRequest;

class CreateEpisodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $podcast = $this->route('podcast');
        return $podcast && $podcast->isOwnedBy(auth()->user());
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'show_notes' => ['nullable', 'string'],
            'episode_number' => ['nullable', 'integer', 'min:1'],
            'season_number' => ['nullable', 'integer', 'min:1'],
            'episode_type' => ['required', 'in:full,trailer,bonus'],
            'explicit' => ['boolean'],
            'is_premium' => ['boolean'],
            'audio_file' => [
                'required',
                'file',
                'mimes:mp3,m4a,wav',
                'max:' . (config('podcast.storage.limits.max_episode_size') / 1024), // Convert to KB
            ],
            'artwork' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:10240', // 10MB
                'dimensions:min_width=1400,min_height=1400,max_width=3000,max_height=3000'
            ],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for your episode.',
            'description.required' => 'Please provide a description for your episode.',
            'audio_file.required' => 'Please upload an audio file for your episode.',
            'audio_file.max' => 'Audio file size must not exceed ' . (config('podcast.storage.limits.max_episode_size') / 1048576) . 'MB.',
            'scheduled_for.after' => 'Scheduled date must be in the future.',
        ];
    }
}
