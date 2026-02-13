<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        $storeId = $this->route('store')?->id;

        return [
            'name' => [
                'sometimes',
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
