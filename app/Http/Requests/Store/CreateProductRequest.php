<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
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
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:store_products,slug|alpha_dash',
            'description' => 'required|string|max:10000',
            'type' => 'required|in:physical,digital,service,experience',
            'category_id' => 'nullable|exists:store_product_categories,id',
            'price' => 'required|numeric|min:0|max:100000000', // 100M UGX max
            'credit_price' => 'nullable|integer|min:0|max:1000000',
            'stock_quantity' => 'nullable|integer|min:0',
            'sku' => 'nullable|string|max:100|unique:store_products,sku',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|max:5120|mimes:jpeg,png,jpg,gif',
            'digital_file' => 'nullable|file|max:51200', // 50MB for digital products
            'metadata' => 'nullable|array',
            'metadata.duration' => 'nullable|string', // For experiences/services
            'metadata.location' => 'nullable|string',
            'metadata.features' => 'nullable|array',
            'requires_shipping' => 'boolean',
            'shipping_fee' => 'nullable|numeric|min:0',
            'variants' => 'nullable|array',
            'variants.*.name' => 'required|string',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.stock' => 'nullable|integer|min:0',
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
            'variants.*.name' => 'variant name',
            'variants.*.price' => 'variant price',
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
            'slug.alpha_dash' => 'The product URL can only contain letters, numbers, dashes and underscores.',
            'credit_price.max' => 'Credit price cannot exceed 1,000,000 credits.',
            'price.max' => 'Price cannot exceed 100,000,000 UGX.',
        ];
    }
}
