<?php

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');
        $product = $this->route('product');

        if (! $store || ! $product) {
            return false;
        }

        return $store->canBeManagedBy($this->user())
            && (int) $product->store_id === (int) $store->id;
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'sometimes',
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
                'sometimes',
                'required',
                Rule::in([
                    Product::TYPE_PHYSICAL,
                    Product::TYPE_DIGITAL,
                    Product::TYPE_SERVICE,
                    Product::TYPE_EXPERIENCE,
                    Product::TYPE_TICKET,
                    Product::TYPE_PROMOTION,
                ]),
            ],
            'category_id' => [
                'sometimes',
                'required',
                'exists:product_categories,id',
            ],
            'price_ugx' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'price_credits' => [
                'nullable',
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
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('store_products', 'sku')->ignore($product?->id),
            ],
            'inventory_quantity' => [
                'nullable',
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
            'low_stock_threshold' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'is_digital' => [
                'nullable',
                'boolean',
            ],
            'digital_file' => [
                'nullable',
                'file',
                'max:51200',
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
            'is_featured' => [
                'nullable',
                'boolean',
            ],
            'status' => [
                'nullable',
                Rule::in([
                    Product::STATUS_DRAFT,
                    Product::STATUS_ACTIVE,
                    Product::STATUS_ARCHIVED,
                    Product::STATUS_OUT_OF_STOCK,
                ]),
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ];
    }
}
