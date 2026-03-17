<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->canCreateStore();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'min:3',
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('stores', 'slug'),
            ],
            'owner_mode' => [
                'nullable',
                Rule::in(['user', 'artist']),
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'city' => [
                'nullable',
                'string',
                'max:255',
            ],
            'country' => [
                'nullable',
                'string',
                'max:255',
            ],
            'logo' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg,gif',
                'max:2048', // 2MB
            ],
            'banner' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5120', // 5MB
            ],
            'settings' => [
                'nullable',
                'array',
            ],
            'settings.theme' => [
                'nullable',
                'array',
            ],
            'settings.theme.primary_color' => [
                'nullable',
                'string',
                'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            ],
            'settings.theme.secondary_color' => [
                'nullable',
                'string',
                'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            ],
            'settings.policies' => [
                'nullable',
                'array',
            ],
            'settings.policies.return_days' => [
                'nullable',
                'integer',
                'min:0',
                'max:90',
            ],
            'offers_local_pickup' => [
                'nullable',
                'boolean',
            ],
            'pickup_address' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Store name is required',
            'name.min' => 'Store name must be at least 3 characters',
            'logo.max' => 'Logo must not exceed 2MB',
            'banner.max' => 'Banner must not exceed 5MB',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'settings.theme.primary_color' => 'primary color',
            'settings.theme.secondary_color' => 'secondary color',
            'settings.policies.return_days' => 'return policy days',
        ];
    }
}
