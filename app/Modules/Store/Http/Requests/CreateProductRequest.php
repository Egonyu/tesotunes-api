<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Store\Models\Product;

class CreateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $store = $this->user()->store;

        if (!$store) {
            return false;
        }

        // Check if store can add more products
        return $store->canAddProducts();
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
            ],
            'description' => [
                'required',
                'string',
                'max:2000',
            ],
            'short_description' => [
                'nullable',
                'string',
                'max:500',
            ],
            'product_type' => [
                'required',
                'in:' . implode(',', [
                    Product::TYPE_PHYSICAL,
                    Product::TYPE_DIGITAL,
                    Product::TYPE_SERVICE,
                    Product::TYPE_EXPERIENCE,
                    Product::TYPE_TICKET,
                    Product::TYPE_PROMOTION,
                ]),
            ],
            'category_id' => [
                'required',
                'exists:product_categories,id',
            ],
            'price_ugx' => [
                'required_without:price_credits',
                'numeric',
                'min:0',
            ],
            'price_credits' => [
                'required_without:price_ugx',
                'integer',
                'min:0',
            ],
            'allow_credit_payment' => [
                'nullable',
                'boolean',
            ],
            'allow_hybrid_payment' => [
                'nullable',
                'boolean',
            ],
            'compare_at_price_ugx' => [
                'nullable',
                'numeric',
                'gt:price_ugx',
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                'unique:products,sku',
            ],
            'inventory_quantity' => [
                'required_if:track_inventory,true',
                'integer',
                'min:0',
            ],
            'track_inventory' => [
                'nullable',
                'boolean',
            ],
            'allow_backorder' => [
                'nullable',
                'boolean',
            ],
            'requires_shipping' => [
                'nullable',
                'boolean',
            ],
            'weight' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'dimensions' => [
                'nullable',
                'array',
            ],
            'dimensions.length' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'dimensions.width' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'dimensions.height' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'is_digital' => [
                'nullable',
                'boolean',
            ],
            'digital_file' => [
                'required_if:is_digital,true',
                'file',
                'max:51200', // 50MB
            ],
            'download_limit' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'images' => [
                'nullable',
                'array',
                'max:5',
            ],
            'images.*' => [
                'image',
                'mimes:jpeg,png,jpg',
                'max:5120',
            ],
            'featured_image' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'is_featured' => [
                'nullable',
                'boolean',
            ],
            'meta_title' => [
                'nullable',
                'string',
                'max:60',
            ],
            'meta_description' => [
                'nullable',
                'string',
                'max:160',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required',
            'description.required' => 'Product description is required',
            'product_type.required' => 'Product type is required',
            'product_type.in' => 'Invalid product type',
            'price_ugx.required_without' => 'Either UGX price or Credits price is required',
            'price_credits.required_without' => 'Either Credits price or UGX price is required',
            'category_id.required' => 'Product category is required',
            'category_id.exists' => 'Invalid product category',
            'digital_file.required_if' => 'Digital file is required for digital products',
            'inventory_quantity.required_if' => 'Inventory quantity is required when tracking inventory',
        ];
    }
}
