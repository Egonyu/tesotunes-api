<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class FollowArtistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // No additional fields needed - artist comes from route model binding
        ];
    }

    public function messages(): array
    {
        return [
            // Custom error messages if needed
        ];
    }
}