<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $this->user()->can('update', $store);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $store = $this->route('store');
        $storeId = is_object($store) ? $store->id : null;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'min:3',
            ],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('stores', 'slug')->ignore($storeId),
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
                'max:2048',
            ],
            'banner' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5120',
            ],
            'settings' => [
                'nullable',
                'array',
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
}
