<?php

namespace App\Http\Requests\Podcast;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePodcastRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'summary' => ['nullable', 'string', 'max:500'],
            'language' => ['sometimes', 'string', 'max:10'],
            'explicit_content' => ['boolean'],
            'category_id' => ['sometimes', 'exists:podcast_categories,id'],
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
                'max:' . (config('podcast.storage.limits.max_artwork_size') / 1024),
                'dimensions:min_width=1400,min_height=1400,max_width=3000,max_height=3000'
            ],
        ];
    }
}
