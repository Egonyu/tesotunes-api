<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
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
            'payment_method' => 'required|in:mobile_money,credit,bank_transfer',
            'payment_provider' => 'required_if:payment_method,mobile_money|in:mtn,airtel',
            'phone_number' => 'required_if:payment_method,mobile_money|string|regex:/^256[0-9]{9}$/',
            'use_credits' => 'boolean',
            'credit_amount' => 'nullable|integer|min:0',
            
            // Shipping address
            'shipping_address' => 'required|array',
            'shipping_address.name' => 'required|string|max:255',
            'shipping_address.phone' => 'required|string|regex:/^256[0-9]{9}$/',
            'shipping_address.address' => 'required|string|max:500',
            'shipping_address.city' => 'required|string|max:100',
            'shipping_address.region' => 'required|string|max:100',
            'shipping_address.postal_code' => 'nullable|string|max:20',
            
            'notes' => 'nullable|string|max:1000',
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
            'shipping_address.name' => 'recipient name',
            'shipping_address.phone' => 'recipient phone',
            'shipping_address.address' => 'delivery address',
            'shipping_address.city' => 'city',
            'shipping_address.region' => 'region',
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
            'phone_number.regex' => 'Phone number must be in format 256XXXXXXXXX (Uganda).',
            'shipping_address.phone.regex' => 'Recipient phone must be in format 256XXXXXXXXX (Uganda).',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean phone numbers
        if ($this->has('phone_number')) {
            $phone = preg_replace('/[^0-9]/', '', $this->phone_number);
            if (strlen($phone) === 9) {
                $phone = '256' . $phone;
            } elseif (strlen($phone) === 10 && $phone[0] === '0') {
                $phone = '256' . substr($phone, 1);
            }
            $this->merge(['phone_number' => $phone]);
        }

        if ($this->has('shipping_address.phone')) {
            $phone = preg_replace('/[^0-9]/', '', $this->input('shipping_address.phone'));
            if (strlen($phone) === 9) {
                $phone = '256' . $phone;
            } elseif (strlen($phone) === 10 && $phone[0] === '0') {
                $phone = '256' . substr($phone, 1);
            }
            $this->merge([
                'shipping_address' => array_merge(
                    $this->shipping_address ?? [],
                    ['phone' => $phone]
                )
            ]);
        }
    }
}
