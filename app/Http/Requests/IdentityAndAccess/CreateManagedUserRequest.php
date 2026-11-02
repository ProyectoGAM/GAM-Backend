<?php

namespace App\Http\Requests\IdentityAndAccess;

use App\Models\User;
use Illuminate\Validation\Rule;

final class CreateManagedUserRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'rol_id' => ['required', 'integer', 'min:1', Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'rol_id.required' => 'Debes indicar un único rol.',
            'rol_id.integer' => 'El rol debe ser un identificador entero.',
            'rol_id.exists' => 'El rol seleccionado no existe.',
        ];
    }
}
