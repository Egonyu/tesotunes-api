<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('store'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $storeId = $this->route('store')->id;

        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:stores,slug,' . $storeId,
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
            'status' => 'sometimes|in:active,inactive,suspended',
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
}
