<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product')->store);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $productId = $this->route('product')->id;

        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:store_products,slug,' . $productId,
            'description' => 'required|string|max:10000',
            'type' => 'required|in:physical,digital,service,experience',
            'category_id' => 'nullable|exists:store_product_categories,id',
            'price' => 'required|numeric|min:0|max:100000000',
            'credit_price' => 'nullable|integer|min:0|max:1000000',
            'stock_quantity' => 'nullable|integer|min:0',
            'sku' => 'nullable|string|max:100|unique:store_products,sku,' . $productId,
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|max:5120|mimes:jpeg,png,jpg,gif',
            'digital_file' => 'nullable|file|max:51200',
            'metadata' => 'nullable|array',
            'requires_shipping' => 'boolean',
            'shipping_fee' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:active,inactive,out_of_stock',
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
            'images.*' => 'product image',
        ];
    }
}
