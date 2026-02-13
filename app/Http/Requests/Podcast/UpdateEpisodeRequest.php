<?php

namespace App\Http\Requests\Podcast;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEpisodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $episode = $this->route('episode');
        return $episode && $episode->podcast->isOwnedBy(auth()->user());
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'show_notes' => ['nullable', 'string'],
            'episode_number' => ['nullable', 'integer', 'min:1'],
            'season_number' => ['nullable', 'integer', 'min:1'],
            'episode_type' => ['sometimes', 'in:full,trailer,bonus'],
            'explicit' => ['boolean'],
            'is_premium' => ['boolean'],
            'audio_file' => [
                'nullable',
                'file',
                'mimes:mp3,m4a,wav',
                'max:' . (config('podcast.storage.limits.max_episode_size') / 1024),
            ],
            'artwork' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:10240',
                'dimensions:min_width=1400,min_height=1400,max_width=3000,max_height=3000'
            ],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ];
    }
}
