<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CreateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:stores,slug|alpha_dash',
            'description' => 'nullable|string|max:5000',
            'logo' => 'nullable|image|max:2048|mimes:jpeg,png,jpg,gif',
            'banner' => 'nullable|image|max:5120|mimes:jpeg,png,jpg',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:store_categories,id',
            'settings' => 'nullable|array',
            'settings.payment_methods' => 'nullable|array',
            'settings.payment_methods.*' => 'in:mobile_money,credit,bank_transfer',
            'settings.shipping_enabled' => 'nullable|boolean',
            'settings.accepts_credits' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'categories.*' => 'category',
            'settings.payment_methods.*' => 'payment method',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.alpha_dash' => 'The slug can only contain letters, numbers, dashes and underscores.',
            'slug.unique' => 'This store name is already taken. Please choose a different one.',
        ];
    }
}
