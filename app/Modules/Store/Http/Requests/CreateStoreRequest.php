<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Store\Models\Store;

class CreateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user can create stores
        if (!config('store.enabled', false)) {
            return false;
        }

        // Check if user already has a store
        if ($this->user()->store()->exists()) {
            return false;
        }

        // Check if email is verified
        if (!$this->user()->email_verified_at) {
            return false;
        }

        // Check if user is artist or if user stores are allowed
        if (!$this->user()->hasRole('artist') && !config('store.stores.allow_user_stores', false)) {
            return false;
        }

        return true;
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
            'description' => [
                'nullable',
                'string',
                'max:1000',
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
