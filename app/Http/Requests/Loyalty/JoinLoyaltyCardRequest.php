<?php

namespace App\Http\Requests\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

class JoinLoyaltyCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'tier' => ['required', 'string', 'in:bronze,silver,gold,platinum'],
            'subscription_type' => ['required', 'string', 'in:monthly,yearly'],
            'payment_method' => ['required', 'string', 'in:mobile_money,credits,hybrid'],
            'mobile_number' => ['required_if:payment_method,mobile_money', 'nullable', 'string'],
            'auto_renew' => ['boolean'],
        ];
    }
}
