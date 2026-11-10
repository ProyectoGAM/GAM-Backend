<?php

namespace App\Http\Requests\IdentityAndAccess;

final class ViewRoleRequest extends RoleRequest
{
    protected string $ability = 'view';
}
