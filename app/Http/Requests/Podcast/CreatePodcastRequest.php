<?php

namespace App\Http\Requests\Podcast;

use Illuminate\Foundation\Http\FormRequest;

class CreatePodcastRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'summary' => ['nullable', 'string', 'max:500'],
            'language' => ['required', 'string', 'max:10'],
            'explicit_content' => ['boolean'],
            'category_id' => ['required', 'exists:podcast_categories,id'],
            'subcategory_id' => ['nullable', 'exists:podcast_categories,id'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'copyright' => ['nullable', 'string', 'max:255'],
            'cover_image' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:' . (config('podcast.storage.limits.max_artwork_size') / 1024), // Convert to KB
                'dimensions:min_width=1400,min_height=1400,max_width=3000,max_height=3000'
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for your podcast.',
            'description.required' => 'Please provide a description for your podcast.',
            'category_id.required' => 'Please select a category for your podcast.',
            'cover_image.dimensions' => 'Podcast artwork must be between 1400x1400 and 3000x3000 pixels.',
        ];
    }
}
