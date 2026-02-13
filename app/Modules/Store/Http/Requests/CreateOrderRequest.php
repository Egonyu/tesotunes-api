<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'payment_method' => [
                'required',
                'in:mobile_money,credits,hybrid,bank_transfer',
            ],
            'phone_number' => [
                'required_if:payment_method,mobile_money',
                'string',
                'regex:/^256[0-9]{9}$/',
            ],
            'provider' => [
                'required_if:payment_method,mobile_money',
                'in:mtn,airtel',
            ],
            'hybrid_ugx' => [
                'required_if:payment_method,hybrid',
                'numeric',
                'min:0',
            ],
            'hybrid_credits' => [
                'required_if:payment_method,hybrid',
                'integer',
                'min:0',
            ],
            'shipping_address' => [
                'required',
                'array',
            ],
            'shipping_address.full_name' => [
                'required',
                'string',
                'max:255',
            ],
            'shipping_address.phone' => [
                'required',
                'string',
                'max:20',
            ],
            'shipping_address.address_line_1' => [
                'required',
                'string',
                'max:255',
            ],
            'shipping_address.address_line_2' => [
                'nullable',
                'string',
                'max:255',
            ],
            'shipping_address.city' => [
                'required',
                'string',
                'max:100',
            ],
            'shipping_address.district' => [
                'required',
                'string',
                'max:100',
            ],
            'shipping_address.postal_code' => [
                'nullable',
                'string',
                'max:20',
            ],
            'billing_address' => [
                'nullable',
                'array',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:500',
            ],
            'use_different_billing' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Payment method is required',
            'payment_method.in' => 'Invalid payment method',
            'phone_number.required_if' => 'Phone number is required for mobile money payment',
            'phone_number.regex' => 'Phone number must be in format 256XXXXXXXXX',
            'provider.required_if' => 'Mobile money provider is required',
            'shipping_address.required' => 'Shipping address is required',
            'shipping_address.full_name.required' => 'Full name is required',
            'shipping_address.phone.required' => 'Phone number is required',
            'shipping_address.address_line_1.required' => 'Street address is required',
            'shipping_address.city.required' => 'City is required',
            'shipping_address.district.required' => 'District is required',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'shipping_address.full_name' => 'full name',
            'shipping_address.phone' => 'phone number',
            'shipping_address.address_line_1' => 'street address',
            'shipping_address.address_line_2' => 'apartment/suite',
            'shipping_address.city' => 'city',
            'shipping_address.district' => 'district',
            'shipping_address.postal_code' => 'postal code',
        ];
    }
}
