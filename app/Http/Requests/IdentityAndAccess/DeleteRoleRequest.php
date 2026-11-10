<?php

namespace App\Http\Requests\IdentityAndAccess;

final class DeleteRoleRequest extends RoleRequest
{
    protected string $ability = 'delete';

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1', 'max:2147483647']];
    }
}
