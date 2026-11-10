<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Validation\Rule;

final class StoreRoleRequest extends RoleRequest
{
    protected string $ability = 'create';

    public function rules(): array
    {
        $names = array_keys(config('permission-catalog.permissions'));

        return [
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permisos' => ['present', 'array', 'list', 'max:'.count($names)],
            'permisos.*' => ['required', 'string', 'distinct', Rule::in($names)],
        ];
    }
}
