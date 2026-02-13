<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CreatePromotionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only artists can create promotions for their products
        return $this->user() && $this->user()->role === 'artist';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'type' => 'required|in:product_purchase,social_share,mention,attendance,content_creation',
            
            // Product/Store references
            'product_id' => 'required_if:type,product_purchase|nullable|exists:store_products,id',
            'store_id' => 'required|exists:stores,id',
            
            // Requirements
            'requirements' => 'required|array',
            'requirements.action' => 'required|string',
            'requirements.platform' => 'nullable|in:facebook,twitter,instagram,tiktok,youtube',
            'requirements.min_reach' => 'nullable|integer|min:0',
            'requirements.content_type' => 'nullable|string',
            'requirements.hashtags' => 'nullable|array',
            'requirements.mentions' => 'nullable|array',
            
            // Rewards
            'reward_type' => 'required|in:credits,product,discount,free_item',
            'reward_value' => 'required|numeric|min:0',
            'reward_description' => 'nullable|string',
            
            // Availability
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
            'max_redemptions' => 'nullable|integer|min:1',
            'max_per_user' => 'nullable|integer|min:1',
            
            // Media
            'image' => 'nullable|image|max:5120|mimes:jpeg,png,jpg',
            
            // Terms
            'terms' => 'nullable|string|max:10000',
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
            'requirements.action' => 'required action',
            'requirements.platform' => 'platform',
            'requirements.min_reach' => 'minimum reach',
            'starts_at' => 'start date',
            'ends_at' => 'end date',
            'max_redemptions' => 'maximum redemptions',
            'max_per_user' => 'maximum per user',
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
            'starts_at.after' => 'Promotion must start in the future.',
            'ends_at.after' => 'End date must be after start date.',
        ];
    }
}
