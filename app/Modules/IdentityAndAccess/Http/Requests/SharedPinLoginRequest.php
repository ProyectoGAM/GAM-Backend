<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SharedPinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'usuario_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'],
        ];
    }
}
