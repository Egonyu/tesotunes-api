<?php

namespace App\Http\Requests\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

class CreateRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->artist;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:5000'],
            'type'                => ['required', 'in:content,merchandise,experience,discount,points'],
            'required_tier'       => ['required', 'string', 'in:bronze,silver,gold,platinum'],
            'content_type'        => ['required_if:type,content', 'nullable', 'string', 'in:audio,video,image,document'],
            'content_url'         => ['required_if:type,content', 'nullable', 'string', 'max:500'],
            'product_id'          => ['required_if:type,merchandise', 'nullable', 'integer', 'exists:store_products,id'],
            'discount_percentage' => ['required_if:type,discount', 'nullable', 'numeric', 'min:1', 'max:100'],
            'event_id'            => ['required_if:type,experience', 'nullable', 'integer', 'exists:events,id'],
            'experience_type'     => ['required_if:type,experience', 'nullable', 'string', 'max:100'],
            'points_amount'       => ['required_if:type,points', 'nullable', 'integer', 'min:1'],
            'is_active'           => ['boolean'],
            'available_from'      => ['nullable', 'date'],
            'available_until'     => ['nullable', 'date', 'after:available_from'],
            'max_redemptions'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
