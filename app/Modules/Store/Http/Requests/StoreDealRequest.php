<?php

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $store && $this->user() && $store->canBeManagedBy($this->user());
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'discount_label' => ['nullable', 'string', 'max:80'],
            'terms' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'url', 'max:2048'],
            'town' => ['nullable', 'string', 'max:80'],
            'credits_price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'ugx_value' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:now', 'after_or_equal:valid_from'],
            'max_claims' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }
}
