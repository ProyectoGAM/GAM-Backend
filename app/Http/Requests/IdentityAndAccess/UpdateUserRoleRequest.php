<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Validation\Rule;

final class UpdateUserRoleRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignRole', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return ['rol_id' => ['required', 'integer', 'min:1', Rule::exists('roles', 'id')->where('guard_name', 'web')]];
    }

    public function messages(): array
    {
        return [
            'rol_id.required' => 'Debes indicar un único rol.',
            'rol_id.integer' => 'El rol debe ser un identificador entero.',
            'rol_id.min' => 'El identificador del rol debe ser positivo.',
            'rol_id.exists' => 'El rol seleccionado no existe.',
        ];
    }
}
