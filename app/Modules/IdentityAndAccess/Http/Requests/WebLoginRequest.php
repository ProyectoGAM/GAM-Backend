<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WebLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'correo_electronico' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
