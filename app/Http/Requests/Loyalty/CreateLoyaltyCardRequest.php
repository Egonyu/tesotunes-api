<?php

namespace App\Http\Requests\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

class CreateLoyaltyCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->artist;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'banner_url' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'allow_monthly' => ['boolean'],
            'allow_yearly' => ['boolean'],
            'auto_renew' => ['boolean'],

            // Tiers: at least one required
            'tiers' => ['required', 'array', 'min:1', 'max:4'],
            'tiers.*.name' => ['required', 'string', 'max:100'],
            'tiers.*.price_monthly' => ['required', 'numeric', 'min:0'],
            'tiers.*.price_yearly' => ['required', 'numeric', 'min:0'],
            'tiers.*.benefits' => ['required', 'array'],
            'tiers.*.benefits.event_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tiers.*.benefits.early_access_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'tiers.*.benefits.exclusive_content' => ['nullable', 'boolean'],
            'tiers.*.benefits.store_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tiers.*.benefits.loyalty_points_multiplier' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'tiers.*.benefits.badge_icon' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'tiers.required' => 'At least one tier is required.',
            'tiers.min' => 'At least one tier is required.',
            'tiers.max' => 'A maximum of 4 tiers is allowed.',
            'primary_color.regex' => 'Color must be a valid hex value (e.g., #FF6B00).',
            'secondary_color.regex' => 'Color must be a valid hex value (e.g., #FF6B00).',
        ];
    }
}
