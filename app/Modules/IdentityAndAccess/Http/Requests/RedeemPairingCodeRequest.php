<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RedeemPairingCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'size:10', 'regex:/\A[A-Z2-9]+\z/D'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
