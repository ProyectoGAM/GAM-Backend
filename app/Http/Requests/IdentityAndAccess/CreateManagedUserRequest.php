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
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => [$this->filled('email') ? 'required' : 'prohibited', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => [$this->filled('email') ? 'required' : 'prohibited', 'string'],
            'pin' => [$this->filled('email') ? 'prohibited' : 'required', 'string', 'regex:/\A[0-9]{4}\z/D', 'confirmed'],
            'pin_confirmation' => [$this->filled('email') ? 'prohibited' : 'required', 'string'],
            'up_id' => [$this->filled('email') ? 'prohibited' : 'required', 'integer', Rule::exists('production_units', 'id')->where('status', 'active')],
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
