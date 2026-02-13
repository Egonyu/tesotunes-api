<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Store\Models\Product;

class AddToCartRequest extends FormRequest
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
            'product_id' => [
                'required',
                'exists:products,id',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:99',
            ],
            'payment_method' => [
                'nullable',
                'in:ugx,credits,hybrid',
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
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->product_id) {
                $product = Product::find($this->product_id);

                if ($product && $product->status !== Product::STATUS_ACTIVE) {
                    $validator->errors()->add('product_id', 'This product is not available');
                }

                if ($product && $product->track_inventory) {
                    if ($product->inventory_quantity < $this->quantity) {
                        $validator->errors()->add('quantity', 'Insufficient inventory. Only ' . $product->inventory_quantity . ' available');
                    }
                }

                // Validate payment method compatibility
                if ($this->payment_method === 'credits' && !$product->allow_credit_payment) {
                    $validator->errors()->add('payment_method', 'Credit payment not accepted for this product');
                }

                if ($this->payment_method === 'hybrid' && !$product->allow_hybrid_payment) {
                    $validator->errors()->add('payment_method', 'Hybrid payment not accepted for this product');
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Product is required',
            'product_id.exists' => 'Invalid product',
            'quantity.required' => 'Quantity is required',
            'quantity.min' => 'Minimum quantity is 1',
            'quantity.max' => 'Maximum quantity is 99',
            'payment_method.in' => 'Invalid payment method',
        ];
    }
}
