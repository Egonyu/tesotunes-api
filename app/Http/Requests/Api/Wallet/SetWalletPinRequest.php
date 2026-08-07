<?php

namespace App\Http\Requests\Api\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class SetWalletPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $length = (int) config('wallet.pin.length', 4);

        return [
            'pin' => ['required', 'string', 'digits:'.$length],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $length = (int) config('wallet.pin.length', 4);

        return [
            'pin.digits' => "Your PIN must be exactly {$length} digits.",
            'pin_confirmation.same' => 'The two PINs do not match.',
        ];
    }
}
