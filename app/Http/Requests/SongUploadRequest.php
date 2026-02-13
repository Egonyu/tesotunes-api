<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for song upload validation
 *
 * Validates all required fields for song upload including:
 * - Audio file validation
 * - Metadata validation
 * - Cover art validation
 * - Artist permissions
 */
class SongUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasPermission('music.upload');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:255',
                'min:1',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'file' => [
                'required',
                'file',
                'mimes:mp3,wav,flac,aac,m4a,ogg',
                'max:51200', // 50MB
                'min:1024',  // 1MB minimum
            ],
            'cover_art' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:10240', // 10MB
                'dimensions:min_width=300,min_height=300,max_width=3000,max_height=3000',
            ],
            'album_id' => [
                'nullable',
                'integer',
                'exists:albums,id',
            ],
            'genre_id' => [
                'nullable',
                'integer',
                'exists:genres,id',
            ],
            'is_free' => [
                'boolean',
            ],
            'is_explicit' => [
                'boolean',
            ],
            'primary_language' => [
                'nullable',
                'string',
                'max:10',
                Rule::in(['en', 'sw', 'lg', 'luo', 'fr', 'es', 'pt', 'ar']),
            ],
            'moods' => [
                'nullable',
                'array',
                'max:5',
            ],
            'moods.*' => [
                'string',
                'max:50',
            ],
            'tags' => [
                'nullable',
                'array',
                'max:10',
            ],
            'tags.*' => [
                'string',
                'max:50',
            ],
            'credits' => [
                'nullable',
                'array',
            ],
            'credits.*.name' => [
                'required_with:credits',
                'string',
                'max:255',
            ],
            'credits.*.role' => [
                'required_with:credits',
                'string',
                'max:100',
            ],
            'release_date' => [
                'nullable',
                'date',
                'after_or_equal:today',
            ],
            'lyrics' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'isrc_code_code' => [
                'nullable',
                'string',
                'regex:/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/',
                'unique:songs,isrc_code',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Song title is required.',
            'title.max' => 'Song title cannot exceed 255 characters.',
            'file.required' => 'Audio file is required.',
            'file.mimes' => 'Audio file must be one of: MP3, WAV, FLAC, AAC, M4A, OGG.',
            'file.max' => 'Audio file size cannot exceed 50MB.',
            'file.min' => 'Audio file must be at least 1MB.',
            'cover_art.image' => 'Cover art must be an image file.',
            'cover_art.mimes' => 'Cover art must be JPEG, JPG, PNG, or WebP.',
            'cover_art.max' => 'Cover art size cannot exceed 10MB.',
            'cover_art.dimensions' => 'Cover art must be at least 300x300 pixels and no more than 3000x3000 pixels.',
            'album_id.exists' => 'Selected album does not exist.',
            'genre_id.exists' => 'Selected genre does not exist.',
            'language.in' => 'Language must be one of the supported languages.',
            'moods.max' => 'You can select a maximum of 5 moods.',
            'tags.max' => 'You can add a maximum of 10 tags.',
            'release_date.after_or_equal' => 'Release date cannot be in the past.',
            'isrc_code.regex' => 'isrc_code must be in the format: CCAAA1234567 (country code + registrant code + year + designation).',
            'isrc_code.unique' => 'This isrc_code is already in use.',
        ];
    }

    /**
     * Get custom attribute names for validation.
     */
    public function attributes(): array
    {
        return [
            'file' => 'audio file',
            'cover_art' => 'cover art',
            'album_id' => 'album',
            'genre_id' => 'genre',
            'is_free' => 'free song',
            'is_explicit' => 'explicit content',
            'moods.*' => 'mood',
            'tags.*' => 'tag',
            'credits.*.name' => 'credit name',
            'credits.*.role' => 'credit role',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_free' => $this->boolean('is_free'),
            'is_explicit' => $this->boolean('is_explicit'),
        ]);

        // Clean up arrays
        if ($this->has('moods') && is_array($this->moods)) {
            $this->merge([
                'moods' => array_filter($this->moods, fn($mood) => !empty($mood))
            ]);
        }

        if ($this->has('tags') && is_array($this->tags)) {
            $this->merge([
                'tags' => array_filter($this->tags, fn($tag) => !empty($tag))
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Custom validation for audio file duration
            if ($this->hasFile('file')) {
                $file = $this->file('file');

                // You would use a library like getID3 to extract duration
                // For now, we'll skip this validation
                // $duration = $this->getAudioDuration($file);
                // if ($duration < 30 || $duration > 900) {
                //     $validator->errors()->add('file', 'Audio file must be between 30 seconds and 15 minutes.');
                // }
            }

            // Validate that user can upload to selected album
            if ($this->filled('album_id')) {
                $album = \App\Models\Album::find($this->album_id);
                if ($album && $album->artist_id !== auth()->id()) {
                    $validator->errors()->add('album_id', 'You can only upload songs to your own albums.');
                }
            }
        });
    }
}