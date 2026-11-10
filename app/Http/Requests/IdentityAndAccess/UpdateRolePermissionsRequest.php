<?php

namespace App\Http\Requests\IdentityAndAccess;

use Illuminate\Validation\Rule;

final class UpdateRolePermissionsRequest extends RoleRequest
{
    protected string $ability = 'update';

    public function rules(): array
    {
        $names = array_keys(config('permission-catalog.permissions'));

        return [
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'permisos' => ['present', 'array', 'list', 'max:'.count($names)],
            'permisos.*' => ['required', 'string', 'distinct', Rule::in($names)],
        ];
    }
}
