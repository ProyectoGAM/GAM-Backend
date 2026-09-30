<?php

namespace App\Http\Requests\IdentityAndAccess;

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', Rule::in(['admin', 'delivery', 'employee'])],
        ];
    }
}
