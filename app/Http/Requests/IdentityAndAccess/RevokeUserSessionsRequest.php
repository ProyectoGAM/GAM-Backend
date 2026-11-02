<?php

namespace App\Http\Requests\IdentityAndAccess;

final class RevokeUserSessionsRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('revokeSessions', $this->route('user')) ?? false;
    }
}
