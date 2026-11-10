<?php

namespace App\Http\Requests\IdentityAndAccess;

final class UpdateRoleRequest extends RoleRequest
{
    protected string $ability = 'update';

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
