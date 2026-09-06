<?php

namespace App\Modules\IdentityAndAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'correo_electronico' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'rol' => ['required', 'string', Rule::in(['admin', 'delivery', 'employee'])],
        ];
    }
}
